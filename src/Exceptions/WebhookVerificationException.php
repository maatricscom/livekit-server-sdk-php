<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

final class WebhookVerificationException extends \RuntimeException implements LiveKitException
{
    public static function missingAuthorizationHeader(): self
    {
        return new self('The webhook request carried no Authorization header.');
    }

    public static function invalidToken(\Throwable $previous): self
    {
        return new self('The webhook Authorization token could not be verified.', 0, $previous);
    }

    public static function checksumMismatch(): self
    {
        return new self(
            'The webhook body does not match the sha256 claim in its token. '
            . 'Make sure you are passing the raw request body — re-serialized JSON will not match.'
        );
    }

    public static function missingSha256Claim(): self
    {
        return new self('The webhook token carried no sha256 claim.');
    }
}
