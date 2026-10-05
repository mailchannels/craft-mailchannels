<?php

declare(strict_types=1);

use MailChannels\Craft\Adapter;
use MailChannels\Craft\Plugin;
use MailChannels\Craft\Transport;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;

final class FixtureHttpClient implements ClientInterface
{
    public array $requests = [];
    public function __construct(public ResponseInterface|Throwable $result) {}
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        if ($this->result instanceof Throwable) { throw $this->result; }
        return $this->result;
    }
}

final class IntegrationTest extends TestCase
{
    private function message(): craft\mail\Message
    {
        return (new craft\mail\Message())
            ->setFrom(['sender@example.com' => 'Sender'])
            ->setTo('to@example.net')
            ->setCc('cc@example.net')->setBcc('bcc@example.net')
            ->setReplyTo('reply@example.com')
            ->setSubject('Craft fixture')->setTextBody('Text body')->setHtmlBody('<p>HTML body</p>')
            ->attachContent('fixture attachment', ['fileName' => 'test.txt', 'contentType' => 'text/plain']);
    }

    public function testRegistrationAndTemplateRoot(): void
    {
        new Plugin('mailchannels');
        self::assertContains(Adapter::class, craft\helpers\MailerHelper::allMailerTransportTypes());
        $event = new craft\events\RegisterTemplateRootsEvent();
        yii\base\Event::trigger(craft\web\View::class, craft\web\View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, $event);
        self::assertFileExists($event->roots['mailchannels'] . '/settings.twig');
    }

    public function testEnvironmentKeyIsNotStoredInSettings(): void
    {
        putenv('MAILCHANNELS_CRAFT_FIXTURE_KEY=fixture-secret-never-real');
        $adapter = new Adapter(['apiKeyEnv' => 'MAILCHANNELS_CRAFT_FIXTURE_KEY']);
        try {
            self::assertTrue($adapter->validate());
            self::assertSame(['apiKeyEnv' => 'MAILCHANNELS_CRAFT_FIXTURE_KEY'], $adapter->getSettings());
            self::assertInstanceOf(Transport::class, $adapter->defineTransport());
            self::assertStringNotContainsString('fixture-secret', (string)$adapter->defineTransport());
        } finally { putenv('MAILCHANNELS_CRAFT_FIXTURE_KEY'); }
    }

    public function testMissingKeyFailsBeforeSending(): void
    {
        $adapter = new Adapter(['apiKeyEnv' => 'MAILCHANNELS_CRAFT_MISSING_KEY']);
        $this->expectException(InvalidArgumentException::class);
        $adapter->defineTransport();
    }

    public function testLiteralKeyAndExpressionRejected(): void
    {
        foreach (['secret-key-value', '$MAILCHANNELS_API_KEY', '@alias', '', 'KEY\nOTHER'] as $value) {
            self::assertFalse((new Adapter(['apiKeyEnv' => $value]))->validate());
        }
    }

    public function testCraftMessageReachesSdkWithRecipientsAndAttachment(): void
    {
        $client = new FixtureHttpClient(new Response(202, [], json_encode([
            'request_id' => 'request-fixture', 'results' => [['index' => 0, 'status' => 'sent', 'message_id' => 'fixture-message']]
        ])));
        $transport = new Transport('fixture-secret', $client);
        $message = $this->message()->getSymfonyEmail();
        $message->getHeaders()->addTextHeader('X-MailChannels-Send-Async', 'true');
        $message->getHeaders()->addTextHeader('X-Craft-Fixture', 'preserved');
        $sent = $transport->send($message);
        self::assertSame('true', $message->getHeaders()->get('X-MailChannels-Send-Async')->getBodyAsString());
        self::assertSame('fixture-message', $sent->getMessageId());
        self::assertCount(1, $client->requests);
        $request = $client->requests[0];
        self::assertSame('https://api.mailchannels.net/tx/v1/send', (string)$request->getUri());
        self::assertSame('fixture-secret', $request->getHeaderLine('X-Api-Key'));
        $body = json_decode((string)$request->getBody(), true);
        self::assertSame('to@example.net', $body['personalizations'][0]['to'][0]['email']);
        self::assertSame('cc@example.net', $body['personalizations'][0]['cc'][0]['email']);
        self::assertSame('bcc@example.net', $body['personalizations'][0]['bcc'][0]['email']);
        self::assertSame('reply@example.com', $body['reply_to']['email']);
        self::assertSame('fixture attachment', base64_decode($body['attachments'][0]['content']));
        self::assertSame('Craft fixture', $body['subject']);
        self::assertCount(2, $body['content']);
        self::assertArrayNotHasKey('Bcc', $body['headers'] ?? []);
        self::assertSame('preserved', $body['headers']['X-Craft-Fixture']);
        self::assertArrayNotHasKey('X-MailChannels-Send-Async', $body['headers']);
    }

    public function testEnvelopeOverrideIsHonored(): void
    {
        $client = new FixtureHttpClient(new Response(202, [], '{"request_id":"fixture","results":[{"index":0,"status":"sent"}]}'));
        $message = $this->message();
        (new Transport('fixture-key', $client))->send($message->getSymfonyEmail(), new Envelope(new Address('envelope@example.com'), [new Address('actual@example.net')]));
        $body = json_decode((string)$client->requests[0]->getBody(), true);
        self::assertSame('actual@example.net', $body['personalizations'][0]['to'][0]['email']);
        self::assertSame('envelope@example.com', $body['envelope_from']['email']);
        self::assertArrayNotHasKey('cc', $body['personalizations'][0]);
        self::assertArrayNotHasKey('bcc', $body['personalizations'][0]);
    }

    public function testFailureMalformedAndHttpErrorsAreSanitizedAndNeverRetried(): void
    {
        $results = [
            new Response(202, [], '{"results":[{"status":"failed","message":"fixture-secret"}]}'),
            new Response(202, [], '{"results":[]}'),
            new Response(202, [], '{"results":[{"index":0,"status":"sent"}]}'),
            new Response(202, [], '{"request_id":"fixture","results":[{"index":1,"status":"sent"}]}'),
            new Response(200, [], '{"data":["rendered email"]}'),
            new Response(202, [], 'invalid fixture-secret'),
            new Response(401, [], '{"message":"fixture-secret"}'),
            new Response(429, [], '{"message":"fixture-secret"}'),
            new Response(500, [], '{"message":"fixture-secret"}'),
            new Response(302, ['Location' => 'https://example.org/fixture-secret']),
            new RuntimeException('Request timed out with fixture-secret'),
        ];
        foreach ($results as $result) {
            $client = new FixtureHttpClient($result);
            try {
                (new Transport('fixture-secret', $client))->send($this->message()->getSymfonyEmail());
                self::fail('Must fail closed');
            } catch (TransportException $e) {
                self::assertStringNotContainsString('fixture-secret', $e->getMessage());
                self::assertNull($e->getPrevious());
            }
            self::assertCount(1, $client->requests);
        }
    }
}
