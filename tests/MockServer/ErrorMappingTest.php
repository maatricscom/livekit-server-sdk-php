<?php

declare(strict_types=1);

namespace LiveKit\Tests\MockServer;

use LiveKit\ClientOptions;
use LiveKit\Enums\WireFormat;
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\CreateSipParticipantOptions;
use LiveKit\Tests\MockServer\Support\MockServerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Checks that a Twirp error envelope written by LiveKit's own code maps onto our
 * exception types.
 *
 * The unit tests assert the same mapping, but against envelopes this repository
 * wrote itself -- which proves only that we can parse what we believe LiveKit
 * sends. Here the envelope is produced by the server, so the shape under test is
 * theirs rather than our idea of theirs.
 *
 * Note that Twirp answers in JSON whatever the request content type was, so the
 * error path is identical for both wire formats. The status/code cases therefore
 * run once; only the SIP case, whose metadata the dialing RPCs carry, is worth
 * running per format.
 */
final class ErrorMappingTest extends MockServerTestCase
{
    /** @return iterable<string, array{int, string}> */
    public static function twirpErrors(): iterable
    {
        yield 'invalid_argument' => [400, 'invalid_argument'];
        yield 'permission_denied' => [403, 'permission_denied'];
        yield 'not_found' => [404, 'not_found'];
        yield 'internal' => [500, 'internal'];
        yield 'unavailable' => [503, 'unavailable'];
    }

    #[DataProvider('twirpErrors')]
    public function test_a_twirp_error_envelope_becomes_a_twirp_exception(int $status, string $code): void
    {
        // failRegions:[0] makes the primary -- the only listener we talk to here
        // -- fail. failover is not in play: this host is not a LiveKit Cloud domain.
        $api = $this->api(['failRegions' => [0], 'failStatus' => $status, 'failTwirpCode' => $code]);

        try {
            $api->room->createRoom(new CreateRoomOptions(name: 'error-room'));
            self::fail('Expected the call to throw.');
        } catch (TwirpException $e) {
            self::assertSame($code, $e->getTwirpCode());
            self::assertSame($status, $e->getHttpStatus());
            self::assertNotInstanceOf(SipCallError::class, $e, 'A non-SIP error must not be a SipCallError.');
        }
    }

    public function test_a_dropped_connection_becomes_an_unavailable_exception(): void
    {
        // failMode "drop" closes the connection without a response, so this is a
        // transport error rather than an error envelope -- the one path where the
        // PSR-18 client throws at us instead of handing back a response.
        $api = $this->api(['failRegions' => [0], 'failMode' => 'drop']);

        try {
            $api->room->createRoom(new CreateRoomOptions(name: 'dropped-room'));
            self::fail('Expected the call to throw.');
        } catch (TwirpException $e) {
            self::assertSame('unavailable', $e->getTwirpCode());
            self::assertSame(0, $e->getHttpStatus(), 'There was no response, so there is no HTTP status.');
        }
    }

    /** @return iterable<string, array{WireFormat}> */
    public static function wireFormats(): iterable
    {
        yield 'binary protobuf' => [WireFormat::Protobuf];
        yield 'json' => [WireFormat::Json];
    }

    #[DataProvider('wireFormats')]
    public function test_a_sip_dialing_failure_becomes_a_sip_call_error(WireFormat $format): void
    {
        // The mock derives the Twirp code and the sip_status_code / sip_status /
        // error_details metadata from this exactly as the real server does.
        $api = $this->api(
            ['delayMs' => 0, 'sipStatus' => ['code' => 486, 'status' => 'Busy Here']],
            new ClientOptions(wireFormat: $format),
        );

        try {
            $api->sip->createSipParticipant(
                'ST_busy',
                '+15551234567',
                'busy-room',
                new CreateSipParticipantOptions(participantIdentity: 'caller'),
            );
            self::fail('Expected the dial to throw.');
        } catch (SipCallError $e) {
            self::assertSame(486, $e->getSipStatusCode());
            self::assertSame('Busy Here', $e->getSipStatus());
        }
    }
}
