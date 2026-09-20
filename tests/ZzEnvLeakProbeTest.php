<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\Tests\Support\TestCase;

/**
 * Runs last (sorted by name) to ensure no earlier test leaked environment variables.
 * This test would have caught the environment restoration bugs fixed in the suite.
 */
final class ZzEnvLeakProbeTest extends TestCase
{
    public function test_zz_environment_survived_the_suite(): void
    {
        self::assertSame(
            self::$originalLivekitUrl,
            getenv('LIVEKIT_URL'),
            'a preceding test clobbered LIVEKIT_URL without restoring it',
        );
        self::assertSame(
            self::$originalLivekitApiKey,
            getenv('LIVEKIT_API_KEY'),
            'a preceding test clobbered LIVEKIT_API_KEY without restoring it',
        );
        self::assertSame(
            self::$originalLivekitApiSecret,
            getenv('LIVEKIT_API_SECRET'),
            'a preceding test clobbered LIVEKIT_API_SECRET without restoring it',
        );
    }
}
