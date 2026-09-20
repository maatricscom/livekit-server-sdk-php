<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

final class ConfigurationException extends \InvalidArgumentException implements LiveKitException
{
    public static function missingCredentials(): self
    {
        return new self(
            'LiveKit credentials are required: either an API key and secret, or a pre-signed '
            . 'token (ClientOptions::$token). Pass them to the constructor, or set '
            . 'LIVEKIT_API_KEY and LIVEKIT_API_SECRET, or LIVEKIT_TOKEN, in the environment. '
            . 'Note that the environment is read only when nothing was passed in at all: an '
            . 'API key given to the constructor is not completed with a secret from the '
            . 'environment.'
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
     * Raised for a host that was given but cannot address a server, which is a
     * different mistake from not giving one at all and deserves to say so.
     */
    public static function invalidHost(string $host): self
    {
        return new self(sprintf(
            'The LiveKit host "%s" is not a usable URL. It needs a scheme and a host, '
            . 'as in https://my-project.livekit.cloud — ws:// and wss:// are accepted too '
            . 'and are rewritten to http(s), since the HTTP API lives on the same origin.',
            $host
        ));
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
