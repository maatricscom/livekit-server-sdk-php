<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

/**
 * A token that could not be accepted: a bad signature, an expired or not-yet-valid
 * window, a malformed token, or one minted for a different API key.
 *
 * The underlying cause is kept as the previous exception — firebase/php-jwt
 * distinguishes an expired token from a forged one, and a caller that wants to
 * tell them apart still can. What this adds is that every way verification can
 * fail is a LiveKitException, so `catch (LiveKitException)` means what it says.
 */
final class TokenVerificationException extends \RuntimeException implements LiveKitException
{
    /** Longest issuer echoed back into a message; it is data from the token. */
    private const ISSUER_EXCERPT = 128;

    public static function rejected(\Throwable $previous): self
    {
        return new self(
            sprintf('The token could not be verified: %s', $previous->getMessage()),
            0,
            $previous
        );
    }

    /**
     * The signature is checked before this, so a token reaching here was signed
     * with our own secret — it is simply not the key it was minted for. That is
     * what makes it worth naming separately: it points at a configuration
     * mismatch rather than at an attack.
     */
    public static function issuerMismatch(string $expected, mixed $actual): self
    {
        $seen = is_string($actual) ? $actual : get_debug_type($actual);

        if (strlen($seen) > self::ISSUER_EXCERPT) {
            $seen = substr($seen, 0, self::ISSUER_EXCERPT) . '...';
        }

        return new self(sprintf(
            'The token was issued for API key "%s", but this verifier is configured for "%s".',
            $seen,
            $expected
        ));
    }
}
