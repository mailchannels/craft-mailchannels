<?php

declare(strict_types=1);

namespace MailChannels\Craft;

use MailChannels\Version;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Names the plugin before the SDK in User-Agent; the SDK's Symfony transport has no option for it. */
final class UserAgentClient implements ClientInterface
{
    public function __construct(private readonly ClientInterface $inner, private readonly string $pluginVersion) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return $this->inner->sendRequest($request->withHeader('User-Agent', self::userAgent($this->pluginVersion)));
    }

    public static function userAgent(string $pluginVersion): string
    {
        return 'mailchannels-craft/' . $pluginVersion . ' ' . Version::userAgent();
    }
}
