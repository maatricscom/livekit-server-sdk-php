<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

final class ConfigurationException extends \InvalidArgumentException implements LiveKitException
{
    public static function missingCredentials(): self
    {
        return new self(
            'LiveKit API key and secret are required. Pass them to the constructor, '
            . 'or set the LIVEKIT_API_KEY and LIVEKIT_API_SECRET environment variables.'
        );
    }

    public static function missingHost(): self
    {
        return new self(
            'A LiveKit host is required. Pass it to the constructor, '
            . 'or set the LIVEKIT_URL environment variable.'
        );
    }

    /**
     * firebase/php-jwt v7 rejects HMAC keys shorter than 32 bytes with a bare
     * DomainException. We surface that as an SDK error naming the requirement.
     */
    public static function secretTooShort(int $length): self
    {
        return new self(sprintf(
            'The LiveKit API secret must be at least 32 bytes for HS256 signing; got %d. '
            . 'Use the real secret from your LiveKit project rather than a placeholder.',
            $length
        ));
    }

    public static function noHttpClient(\Throwable $previous): self
    {
        return new self(
            'No PSR-18 HTTP client could be discovered. Install one (for example '
            . 'guzzlehttp/guzzle or symfony/http-client) or pass a client explicitly '
            . 'to the service client constructor.',
            0,
            $previous
        );
    }

    public static function noHttpFactory(\Throwable $previous): self
    {
        return new self(
            'No PSR-17 HTTP factory could be discovered. Install one (for example '
            . 'nyholm/psr7 or guzzlehttp/psr7) or pass request and stream factories '
            . 'explicitly to the service client constructor.',
            0,
            $previous
        );
    }
}
