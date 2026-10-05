<?php

declare(strict_types=1);

namespace MailChannels\Craft;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** SDK 2.2 does not reject a synchronous 202 result whose status is failed. */
final class ResponseGuard implements ClientInterface
{
    public function __construct(private readonly ClientInterface $inner) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        if ($request->getUri()->getPath() !== '/tx/v1/send' || $response->getStatusCode() !== 202) {
            throw new AcceptanceException('Unexpected MailChannels response.');
        }
        $body = $response->getBody();
        if (!$body->isSeekable()) {
            throw new AcceptanceException('Cannot inspect MailChannels acceptance response.');
        }
        $position = $body->tell();
        $data = json_decode((string)$body, true);
        $body->seek($position);
        // The SDK maps one Symfony email into one personalization.
        $results = $data['results'] ?? null;
        if (!is_string($data['request_id'] ?? null) || $data['request_id'] === ''
            || !is_array($results) || count($results) !== 1
            || ($results[0]['index'] ?? null) !== 0 || ($results[0]['status'] ?? null) !== 'sent') {
            throw new AcceptanceException('MailChannels did not confirm acceptance.');
        }
        return $response;
    }
}
