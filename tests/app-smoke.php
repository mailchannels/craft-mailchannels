<?php
/** Run in the installed isolated Craft fixture; no real HTTP client is used. */
declare(strict_types=1);
require '/app/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';
use MailChannels\Craft\Adapter;
use MailChannels\Craft\Transport;
use craft\helpers\App;
use craft\helpers\MailerHelper;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

function verify(bool $condition, string $name): void {
    if (!$condition) { throw new RuntimeException($name); }
    echo "PASS: $name\n";
}
verify($app->getPlugins()->isPluginEnabled('mailchannels'), 'plugin installed and enabled');
verify(in_array(Adapter::class, MailerHelper::allMailerTransportTypes(), true), 'adapter registered in real Craft');
$settings = App::mailSettings();
$settings->transportType = Adapter::class;
$settings->transportSettings = ['apiKeyEnv' => 'MAILCHANNELS_CRAFT_FIXTURE_KEY'];
$settings->fromEmail = 'sender@example.com';
$settings->fromName = 'Fixture';
$app->getProjectConfig()->set('email', $settings->toArray());
$loaded = App::mailSettings();
$adapter = MailerHelper::createTransportAdapter($loaded->transportType, $loaded->transportSettings);
verify($adapter->getSettings() === ['apiKeyEnv' => 'MAILCHANNELS_CRAFT_FIXTURE_KEY'], 'saved settings contain only environment variable name');
$view = $app->getView();
$view->setTemplateMode(craft\web\View::TEMPLATE_MODE_CP);
$html = $adapter->getSettingsHtml();
verify(str_contains($html, 'MAILCHANNELS_CRAFT_FIXTURE_KEY') && !str_contains($html, 'fixture-secret-not-real'), 'editable settings template renders without key');
$html = $adapter->getReadOnlySettingsHtml();
verify(str_contains($html, 'disabled') && !str_contains($html, 'fixture-secret-not-real'), 'read-only template disables field without key');
verify($adapter->defineTransport() instanceof Transport, 'saved environment setting creates production transport');

$client = new class implements ClientInterface {
    public int $calls = 0;
    public bool $fail = false;
    public function sendRequest(RequestInterface $request): ResponseInterface {
        ++$this->calls;
        return new Response(202, [], json_encode(['request_id' => 'fixture-request', 'results' => [[
            'index' => 0, 'status' => $this->fail ? 'failed' : 'sent', 'message_id' => 'fixture-message',
            'reason' => $this->fail ? 'fixture-secret-not-real' : null,
        ]]]));
    }
};
$mailer = Craft::createObject(App::mailerConfig($loaded));
$mailer->setTransport(new Transport('fixture-secret-not-real', $client));
$message = $mailer->compose()->setFrom('sender@example.com')->setTo('recipient@example.net')->setSubject('Fixture')->setTextBody('Fixture only');
verify($mailer->send($message) === true, 'real Craft mailer reports accepted fixture as success');
$client->fail = true;
verify($mailer->send($message) === false, 'real Craft mailer reports failed result as failure');
verify($client->calls === 2, 'one HTTP request per attempt; no retries');
$app->getLog()->getLogger()->flush(true);
echo "No live API requests or email sent.\n";
