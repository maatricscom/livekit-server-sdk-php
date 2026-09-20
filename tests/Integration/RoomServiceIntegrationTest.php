<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\LiveKitClient;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Proto\Room;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real LiveKit deployment. Skipped unless LIVEKIT_URL,
 * LIVEKIT_API_KEY and LIVEKIT_API_SECRET are all set, so it never gates CI.
 *
 * This is the only place the binary-protobuf content type is exercised against a
 * real server. No official LiveKit SDK sends application/protobuf, so run this
 * against your own project before every release.
 */
final class RoomServiceIntegrationTest extends TestCase
{
    private LiveKitClient $livekit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['LIVEKIT_URL', 'LIVEKIT_API_KEY', 'LIVEKIT_API_SECRET'] as $name) {
            if (getenv($name) === false || getenv($name) === '') {
                self::markTestSkipped(sprintf('%s is not set; skipping integration tests.', $name));
            }
        }

        $this->livekit = new LiveKitClient();
    }

    public function test_creates_lists_and_deletes_a_room_over_binary_protobuf(): void
    {
        $name = 'php-sdk-integration-' . bin2hex(random_bytes(4));

        $room = $this->livekit->room->createRoom(new CreateRoomOptions(name: $name, emptyTimeout: 30));

        self::assertInstanceOf(Room::class, $room);
        self::assertSame($name, $room->getName());

        try {
            $names = array_map(
                static fn (Room $r): string => $r->getName(),
                $this->livekit->room->listRooms()
            );

            self::assertContains($name, $names);
        } finally {
            $this->livekit->room->deleteRoom($name);
        }

        $remaining = array_map(
            static fn (Room $r): string => $r->getName(),
            $this->livekit->room->listRooms()
        );

        self::assertNotContains($name, $remaining);
    }
}
