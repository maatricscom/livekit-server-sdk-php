<?php

declare(strict_types=1);

namespace LiveKit\Tests\Support;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const API_KEY = 'devkey';

    /** Must be at least 32 bytes: firebase/php-jwt v7 rejects shorter HMAC keys. */
    protected const API_SECRET = 'secret-that-is-long-enough-for-hs256';

    /** Captured once per process before any test runs, to verify environment survives the test suite. */
    public static string|false $originalLivekitUrl = '';
    public static string|false $originalLivekitApiKey = '';
    public static string|false $originalLivekitApiSecret = '';

    /**
     * Captures the baseline environment variables before any tests run.
     * Must be called from the bootstrap file, not setUpBeforeClass(), so the
     * baseline reflects the actual process start state rather than the state
     * after earlier test classes have run.
     */
    public static function captureEnvironmentBaseline(): void
    {
        // Only capture once per process. On subsequent calls, preserve the original baseline.
        if (self::$originalLivekitUrl !== '') {
            return;
        }

        self::$originalLivekitUrl = getenv('LIVEKIT_URL');
        self::$originalLivekitApiKey = getenv('LIVEKIT_API_KEY');
        self::$originalLivekitApiSecret = getenv('LIVEKIT_API_SECRET');
    }

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

    /**
     * Runs $body with the given environment, restoring whatever was there before —
     * including restoring "unset" for a variable that did not exist. getenv() returns
     * false rather than null when unset, so the restore has to branch.
     *
     * @param array<string, string|null> $env  null unsets the variable for the duration
     */
    protected function withEnv(array $env, callable $body): void
    {
        $original = [];

        foreach ($env as $name => $value) {
            $original[$name] = getenv($name);
            $value === null ? putenv($name) : putenv($name . '=' . $value);
        }

        try {
            $body();
        } finally {
            foreach ($original as $name => $value) {
                $value === false ? putenv($name) : putenv($name . '=' . $value);
            }
        }
    }
}
