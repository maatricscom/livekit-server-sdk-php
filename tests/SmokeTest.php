<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\Tests\Support\TestCase;

final class SmokeTest extends TestCase
{
    public function test_autoloading_and_php_version(): void
    {
        self::assertTrue(PHP_VERSION_ID >= 80300, 'This SDK requires PHP 8.3 or newer');
    }

    public function test_jwt_payload_decoding_helper(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"HS256"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('{"iss":"devkey","video":{"roomCreate":true}}'), '+/', '-_'), '=');

        $claims = $this->decodeJwtPayload($header . '.' . $payload . '.sig');

        self::assertSame('devkey', $claims['iss']);
        self::assertSame(['roomCreate' => true], $claims['video']);
    }
}
