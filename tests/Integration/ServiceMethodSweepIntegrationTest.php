<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\SendDataOptions;
use LiveKit\Options\TrackCompositeOptions;
use LiveKit\Options\UpdateParticipantOptions;
use LiveKit\Proto\DataPacket\Kind;
use LiveKit\Proto\DirectFileOutput;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\StartEgressRequest;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Drives the room, egress and agent-dispatch methods the rest of this suite does
 * not reach, and asserts what a real deployment answers.
 *
 * Most of them cannot be exercised against a live object from a server SDK alone:
 * muting a track needs a published track, and every egress needs a room with media
 * in it plus storage credentials this suite has no business holding. What can be
 * proved without any of that is that the request is one the server understands --
 * that the Twirp route exists, the message encodes into something it can decode,
 * and the grant this SDK mints is accepted. A `not_found` for an object that really
 * is absent proves all three; a wrong route, a mis-encoded field or a missing grant
 * would fail differently, as `bad_route`, `malformed` or `permission_denied`.
 *
 * So the expected code is the assertion here, not an inconvenience to tolerate.
 * Anything creatable *is* created for real -- see the tests either side of this one.
 *
 * Nothing here starts an egress. Each start is aimed at a room that does not exist
 * (or, for the web variant, carries no URL), so the server rejects it before any
 * recording begins and no charge is possible.
 */
final class ServiceMethodSweepIntegrationTest extends IntegrationTestCase
{
    /** @return iterable<string, array{callable(LiveKitAPI, string, string): mixed, ?string}> */
    public static function methods(): iterable
    {
        $file = static function (): EncodedFileOutput {
            $f = new EncodedFileOutput();
            $f->setFilepath('sweep-{time}.mp4');

            return $f;
        };

        // Room service, against a room that exists and a participant that does not.
        yield 'room.sendData' => [static fn (LiveKitAPI $a, string $room): mixed => $a->room->sendData($room, 'sweep', Kind::RELIABLE, new SendDataOptions(topic: 'sweep')), null];
        yield 'room.getParticipant' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->room->getParticipant($room, $ghost), TwirpErrorCode::NOT_FOUND];
        yield 'room.removeParticipant' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->room->removeParticipant($room, $ghost), TwirpErrorCode::NOT_FOUND];
        yield 'room.mutePublishedTrack' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->room->mutePublishedTrack($room, $ghost, 'TR_absent', true), TwirpErrorCode::NOT_FOUND];
        yield 'room.updateParticipant' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->room->updateParticipant($room, $ghost, new UpdateParticipantOptions(name: 'sweep')), TwirpErrorCode::NOT_FOUND];
        yield 'room.updateSubscriptions' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->room->updateSubscriptions($room, $ghost, ['TR_absent'], false), TwirpErrorCode::NOT_FOUND];
        yield 'room.forwardParticipant' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->room->forwardParticipant($room, $ghost, $room . '-dst'), TwirpErrorCode::NOT_FOUND];
        yield 'room.moveParticipant' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->room->moveParticipant($room, $ghost, $room . '-dst'), TwirpErrorCode::NOT_FOUND];

        // Egress, none of which may start. See the class docblock.
        yield 'egress.startRoomCompositeEgress' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->egress->startRoomCompositeEgress($ghost, new EncodedOutputs(file: $file())), TwirpErrorCode::NOT_FOUND];
        yield 'egress.startParticipantEgress' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->egress->startParticipantEgress($ghost, $ghost, new EncodedOutputs(file: $file())), TwirpErrorCode::NOT_FOUND];
        yield 'egress.startTrackCompositeEgress' => [static fn (LiveKitAPI $a, string $room, string $ghost): mixed => $a->egress->startTrackCompositeEgress($ghost, new EncodedOutputs(file: $file()), new TrackCompositeOptions(audioTrackId: 'TR_a', videoTrackId: 'TR_v')), TwirpErrorCode::NOT_FOUND];
        yield 'egress.startTrackEgress' => [static function (LiveKitAPI $a, string $room, string $ghost): mixed {
            $direct = new DirectFileOutput();
            $direct->setFilepath('sweep-track.ogg');

            return $a->egress->startTrackEgress($ghost, $direct, 'TR_absent');
        }, TwirpErrorCode::NOT_FOUND];
        yield 'egress.startWebEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->startWebEgress('', new EncodedOutputs(file: $file())), TwirpErrorCode::INVALID_ARGUMENT];
        yield 'egress.startEgress' => [static function (LiveKitAPI $a, string $room, string $ghost): mixed {
            $request = new StartEgressRequest();
            $request->setRoomName($ghost);

            return $a->egress->startEgress($request);
        }, TwirpErrorCode::NOT_FOUND];
        yield 'egress.updateLayout' => [static fn (LiveKitAPI $a): mixed => $a->egress->updateLayout('EG_absent', 'grid'), TwirpErrorCode::NOT_FOUND];
        yield 'egress.updateStream' => [static fn (LiveKitAPI $a): mixed => $a->egress->updateStream('EG_absent', ['rtmp://example.invalid/sweep']), TwirpErrorCode::NOT_FOUND];
        yield 'egress.stopEgress' => [static fn (LiveKitAPI $a): mixed => $a->egress->stopEgress('EG_absent'), TwirpErrorCode::NOT_FOUND];
    }

    /** @param callable(LiveKitAPI, string, string): mixed $call */
    #[DataProvider('methods')]
    public function test_a_real_deployment_answers_the_rpc(callable $call, ?string $expected): void
    {
        $room = $this->scratchName('sweep');
        $ghost = $this->scratchName('absent');

        $this->cleanUpAfter(
            body: function () use ($call, $expected, $room, $ghost): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $room, emptyTimeout: 30));

                if ($expected === null) {
                    $this->skipIfUnavailable(fn (): mixed => $call($this->livekit, $room, $ghost), 'The service');
                    self::addToAssertionCount(1);

                    return;
                }

                try {
                    $this->skipIfUnavailable(fn (): mixed => $call($this->livekit, $room, $ghost), 'The service');
                } catch (TwirpException $e) {
                    self::assertSame($expected, $e->getTwirpCode(), sprintf(
                        'the server understood the request but answered %s: %s',
                        $e->getTwirpCode(),
                        $e->getMessage()
                    ));

                    return;
                }

                self::fail(sprintf('expected the server to answer %s, but the call succeeded', $expected));
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($room),
            describe: sprintf('room "%s"', $room),
        );
    }

    /**
     * performRpc is the one room RPC whose failure is not an error.
     *
     * PerformRpcResponse carries a payload and nothing else -- the proto has no
     * error field -- and a call aimed at an identity that is not in the room comes
     * back successful and empty, in about a tenth of a second, ignoring the
     * response timeout. This test exists so that stops being folklore: an empty
     * payload does not mean the client answered with nothing.
     */
    public function test_perform_rpc_to_an_absent_identity_succeeds_with_an_empty_payload(): void
    {
        $room = $this->scratchName('rpc');

        $this->cleanUpAfter(
            body: function () use ($room): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $room, emptyTimeout: 30));

                $response = $this->skipIfUnavailable(
                    fn (): mixed => $this->livekit->room->performRpc($room, $this->scratchName('absent'), 'sweep.ping', '{}', 2000),
                    'performRpc',
                );

                self::assertSame('', $response->getPayload(), 'an absent destination answers empty rather than raising');
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($room),
            describe: sprintf('room "%s"', $room),
        );
    }
}
