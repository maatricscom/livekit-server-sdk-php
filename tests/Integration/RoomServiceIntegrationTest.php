<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Options\CreateRoomOptions;
use LiveKit\Options\ListRoomsOptions;
use LiveKit\Options\SendDataOptions;
use LiveKit\Proto\DataPacket\Kind;
use LiveKit\Proto\Room;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Not covered here, and deliberately: updateParticipant, mutePublishedTrack,
 * removeParticipant, updateSubscriptions, forwardParticipant, moveParticipant and
 * performRpc all need a participant connected over WebRTC, which a server SDK
 * cannot produce. The mock server answers them -- that is what RpcSweepTest is
 * for -- but a real deployment can only be asked once a client is in the room.
 */
final class RoomServiceIntegrationTest extends IntegrationTestCase
{
    /** @return iterable<string, array{string}> */
    public static function wireFormats(): iterable
    {
        yield 'binary protobuf' => ['protobuf'];
        yield 'json' => ['json'];
    }

    /**
     * The full lifecycle, in both content types. The binary path matters most:
     * no official LiveKit SDK sends application/protobuf, so nothing upstream
     * would notice if a real deployment stopped accepting it.
     */
    #[DataProvider('wireFormats')]
    public function test_a_room_can_be_created_found_and_deleted(string $wireFormat): void
    {
        $api = $wireFormat === 'json' ? $this->jsonClient() : $this->livekit;
        $name = $this->scratchName('room');

        $this->cleanUpAfter(
            body: function () use ($api, $name): void {
                $room = $api->room->createRoom(new CreateRoomOptions(name: $name, emptyTimeout: 30));

                self::assertInstanceOf(Room::class, $room);
                self::assertSame($name, $room->getName());
                self::assertNotSame('', $room->getSid(), 'the server assigns a sid');

                $filtered = $api->room->listRooms(new ListRoomsOptions(names: [$name]));
                self::assertCount(1, $filtered, 'the names filter reaches the server');
                self::assertSame($name, $filtered[0]->getName());
            },
            cleanup: fn () => $api->room->deleteRoom($name),
            describe: sprintf('room "%s"', $name),
        );

        $remaining = array_map(
            static fn (Room $r): string => $r->getName(),
            $this->livekit->room->listRooms()
        );

        self::assertNotContains($name, $remaining, 'deleteRoom actually removed it');
    }

    public function test_room_metadata_survives_a_round_trip(): void
    {
        $name = $this->scratchName('meta');
        $metadata = json_encode(['tenant' => 'çalışma', 'url' => 'https://example.test/a/b'], JSON_THROW_ON_ERROR);

        $this->cleanUpAfter(
            body: function () use ($name, $metadata): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $name, emptyTimeout: 30));

                // Non-ASCII and a URL on purpose: these are what a re-encoding bug
                // in the JSON path would mangle, and metadata is arbitrary
                // application data, so LiveKit must return the exact bytes.
                $updated = $this->livekit->room->updateRoomMetadata($name, $metadata);

                self::assertSame($metadata, $updated->getMetadata());
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($name),
            describe: sprintf('room "%s"', $name),
        );
    }

    public function test_an_empty_room_reports_no_participants(): void
    {
        $name = $this->scratchName('empty');

        $this->cleanUpAfter(
            body: function () use ($name): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $name, emptyTimeout: 30));

                self::assertSame([], $this->livekit->room->listParticipants($name));

                // Nobody is connected, so this delivers to no one. It still proves
                // the request is accepted, the grant is right and the nonce the
                // SDK attaches does not upset the server.
                $this->livekit->room->sendData(
                    $name,
                    'integration-probe',
                    Kind::RELIABLE,
                    new SendDataOptions(topic: 'php-sdk-it'),
                );
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($name),
            describe: sprintf('room "%s"', $name),
        );
    }

    /**
     * createRoom is idempotent on LiveKit: asking twice returns the same room
     * rather than failing. Worth pinning, because a caller that retries a timed-out
     * createRoom depends on it.
     */
    public function test_creating_the_same_room_twice_returns_the_same_room(): void
    {
        $name = $this->scratchName('twice');

        $this->cleanUpAfter(
            body: function () use ($name): void {
                $first = $this->livekit->room->createRoom(new CreateRoomOptions(name: $name, emptyTimeout: 30));
                $second = $this->livekit->room->createRoom(new CreateRoomOptions(name: $name, emptyTimeout: 30));

                self::assertSame($first->getSid(), $second->getSid());
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($name),
            describe: sprintf('room "%s"', $name),
        );
    }
}
