<?php

declare(strict_types=1);

namespace MailChannels\Craft;

use Psr\Http\Client\ClientExceptionInterface;

final class AcceptanceException extends \RuntimeException implements ClientExceptionInterface
{
}
