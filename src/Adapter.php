<?php

declare(strict_types=1);

namespace MailChannels\Craft;

use Craft;
use craft\helpers\App;
use craft\mail\transportadapters\BaseTransportAdapter;
use GuzzleHttp\Client;
use Symfony\Component\Mailer\Transport\AbstractTransport;

final class Adapter extends BaseTransportAdapter
{
    // Store only an environment-variable name in Craft project config.
    public string $apiKeyEnv = 'MAILCHANNELS_API_KEY';

    public static function displayName(): string
    {
        return 'MailChannels';
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['apiKeyEnv'], 'required'],
            [['apiKeyEnv'], 'match', 'pattern' => '/^[A-Z_][A-Z0-9_]*$/D',
                'message' => 'Enter an environment variable name, not an API key.'],
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return $this->settingsHtml(false);
    }

    public function getReadOnlySettingsHtml(): ?string
    {
        return $this->settingsHtml(true);
    }

    private function settingsHtml(bool $readOnly): string
    {
        return Craft::$app->getView()->renderTemplate('mailchannels/settings', [
            'adapter' => $this, 'readOnly' => $readOnly,
        ]);
    }

    public function defineTransport(): array|AbstractTransport
    {
        if (!$this->validate()) {
            throw new \InvalidArgumentException('Invalid MailChannels API key environment variable name.');
        }
        $key = App::env($this->apiKeyEnv);
        if (!is_string($key) || trim($key) === '') {
            throw new \InvalidArgumentException('Set the configured MailChannels API key environment variable on this server.');
        }
        return new Transport($key, new Client([
            'timeout' => 30, 'connect_timeout' => 10, 'allow_redirects' => false,
        ]));
    }
}
