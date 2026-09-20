<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\Tests\Support\TestCase;

final class SmokeTest extends TestCase
{
    public function test_psr4_autoloading_resolves_both_namespace_roots(): void
    {
        self::assertTrue(
            class_exists(\LiveKit\Exceptions\TwirpException::class),
            'The LiveKit\ PSR-4 root should resolve hand-written classes'
        );

        self::assertTrue(
            class_exists(\LiveKit\Proto\Room::class),
            'The LiveKit\ PSR-4 root should also resolve the generated LiveKit\Proto\ tree'
        );
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
