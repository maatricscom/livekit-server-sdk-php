<?php

declare(strict_types=1);

namespace LiveKit;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use LiveKit\Exceptions\ConfigurationException;

/**
 * Verifies LiveKit access tokens and returns their claims.
 */
final class TokenVerifier
{
    private readonly string $apiKey;

    private readonly string $apiSecret;

    public function __construct(?string $apiKey = null, ?string $apiSecret = null)
    {
        $apiKey ??= self::env('LIVEKIT_API_KEY');
        $apiSecret ??= self::env('LIVEKIT_API_SECRET');

        if ($apiKey === null || $apiKey === '' || $apiSecret === null || $apiSecret === '') {
            throw ConfigurationException::missingCredentials();
        }

        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * The server allows 60 seconds of leeway, so that is the default here too.
     *
     * @return array<string, mixed>
     */
    public function verify(string $token, int $clockToleranceSeconds = 60): array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = $clockToleranceSeconds;

        try {
            $decoded = JWT::decode($token, new Key($this->apiSecret, 'HS256'));
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $claims;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
