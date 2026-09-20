<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

/**
 * An inbound webhook this SDK would not accept: its token did not verify, its body
 * did not match the token's checksum, or the body could not be decoded at all.
 */
final class WebhookVerificationException extends \RuntimeException implements LiveKitException
{
    public static function unparseableBody(\Throwable $previous): self
    {
        return new self(
            'The webhook body could not be decoded as a LiveKit event. It is not the '
            . 'protojson this SDK expects, or it arrived truncated.',
            0,
            $previous
        );
    }

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
