<?php

declare(strict_types=1);

namespace LiveKit\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const API_KEY = 'devkey';

    /** Must be at least 32 bytes: firebase/php-jwt v7 rejects shorter HMAC keys. */
    protected const API_SECRET = 'secret-that-is-long-enough-for-hs256';

    /**
     * Decodes a JWT without verifying it, for asserting claim contents in tests.
     *
     * @return array<string, mixed>
     */
    protected function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts, 'Expected a three-segment JWT');

        $json = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($json, 'JWT payload was not valid base64url');

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
