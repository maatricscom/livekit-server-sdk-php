<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\ClientOptions;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;

/**
 * What a real LiveKit deployment returns when the request is wrong.
 *
 * This SDK's error mapping was built against the Twirp specification and checked
 * against livekit/test-server. Both are models. These tests are the only place
 * the mapping meets the thing being modelled, which matters because every caller
 * branches on it: a `catch (TwirpException)` that never sees the code it expects
 * is a bug nobody notices until production.
 */
final class ErrorShapeIntegrationTest extends IntegrationTestCase
{
    public function test_a_missing_room_produces_a_twirp_exception_with_a_real_code(): void
    {
        $missing = $this->scratchName('does-not-exist');

        try {
            $this->livekit->room->getParticipant($missing, 'nobody');
            self::fail('Expected the server to reject a lookup in a room that does not exist.');
        } catch (TwirpException $e) {
            // The code itself is the server's to choose and has changed upstream
            // before, so this asserts the shape rather than one spelling: a code
            // the Twirp spec defines, and an HTTP status that agrees with it.
            self::assertTrue(
                TwirpErrorCode::isValid($e->getTwirpCode()),
                sprintf('server sent "%s", which is not a Twirp code', $e->getTwirpCode())
            );
            self::assertGreaterThanOrEqual(400, $e->getHttpStatus());
            self::assertNotSame(
                TwirpErrorCode::UNKNOWN,
                $e->getTwirpCode(),
                'an unknown code here means the response was not an error envelope this SDK could read'
            );
        }
    }

    /**
     * A token signed with the wrong secret. The server must reject it, and this
     * SDK must surface that as unauthenticated rather than as something vague --
     * it is the difference between "fix your credentials" and "LiveKit is down".
     */
    public function test_a_bad_secret_is_reported_as_an_authentication_failure(): void
    {
        $url = getenv('LIVEKIT_URL');
        $key = getenv('LIVEKIT_API_KEY');
        self::assertIsString($url);
        self::assertIsString($key);

        $wrong = new LiveKitAPI($url, $key, str_repeat('not-the-real-secret-', 3));

        try {
            $wrong->room->listRooms();
            self::fail('Expected the server to reject a token signed with the wrong secret.');
        } catch (TwirpException $e) {
            self::assertSame(TwirpErrorCode::UNAUTHENTICATED, $e->getTwirpCode());
            self::assertSame(401, $e->getHttpStatus());
        }
    }

    /**
     * The JSON wire format has to fail the same way as the binary one. A caller
     * that switches ClientOptions::$wireFormat should not have to rewrite its
     * error handling.
     */
    public function test_the_json_wire_format_reports_the_same_failure(): void
    {
        $missing = $this->scratchName('does-not-exist');

        $codes = [];

        foreach (['protobuf' => $this->livekit, 'json' => $this->jsonClient()] as $format => $api) {
            try {
                $api->room->getParticipant($missing, 'nobody');
                self::fail(sprintf('Expected a failure over %s.', $format));
            } catch (TwirpException $e) {
                $codes[$format] = $e->getTwirpCode();
            }
        }

        self::assertSame($codes['protobuf'], $codes['json'], 'the two wire formats disagreed about the error');
    }

    /**
     * Not a server round trip, but the failure a caller is most likely to hit
     * first, and it must not reach the network at all.
     */
    public function test_a_host_without_a_scheme_fails_before_any_request(): void
    {
        $this->expectException(ConfigurationException::class);

        new LiveKitAPI('my-project.livekit.cloud', 'key', str_repeat('x', 32), new ClientOptions());
    }
}
