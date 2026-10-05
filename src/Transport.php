<?php

declare(strict_types=1);

namespace MailChannels\Craft;

use GuzzleHttp\Psr7\HttpFactory;
use MailChannels\Plugins\Symfony\Transport\MailChannelsApiTransport;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/** Reuses SDK MIME conversion while preventing sensitive exception propagation. */
final class Transport extends AbstractTransport
{
    private readonly MailChannelsApiTransport $inner;

    public function __construct(#[\SensitiveParameter] string $key, ClientInterface $httpClient)
    {
        parent::__construct();
        $factory = new HttpFactory();
        $this->inner = new MailChannelsApiTransport(
            apiKey: $key,
            baseUrl: 'https://api.mailchannels.net/tx/v1',
            httpClient: new ResponseGuard($httpClient),
            requestFactory: $factory,
            streamFactory: $factory,
        );
    }

    public function __toString(): string
    {
        return 'mailchannels+api://api.mailchannels.net';
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $original = $message->getOriginalMessage();
            if (!$original instanceof Message) {
                throw new \InvalidArgumentException('A structured email is required.');
            }
            $email = clone MessageConverter::toEmail($original);
            // Always use synchronous acceptance results for Craft's success flag.
            $email->getHeaders()->remove('X-MailChannels-Send-Async');
            $email->getHeaders()->addTextHeader('X-MailChannels-Send-Async', 'false');
            // Preserve envelope overrides without retaining original CC/BCC recipients.
            $allowed = array_map(static fn($address) => $address->getAddress(), $message->getEnvelope()->getRecipients());
            $filter = static fn($address) => in_array($address->getAddress(), $allowed, true);
            $email->cc(...array_filter($email->getCc(), $filter));
            $email->bcc(...array_filter($email->getBcc(), $filter));
            $sent = $this->inner->send($email, $message->getEnvelope());
            if ($sent === null) {
                throw new \RuntimeException('No send result.');
            }
            $message->setMessageId($sent->getMessageId());
        } catch (\Throwable) {
            // Do not chain exceptions that may include HTTP bodies, request keys,
            // DKIM material or recipient addresses into Craft logs.
            throw new TransportException('MailChannels did not confirm acceptance of this message. Check delivery records before retrying; the message may already have been accepted.');
        }
    }
}
