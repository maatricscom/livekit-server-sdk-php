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
 *
 * Also worth establishing during that pre-release run: whether the `X-Twirp-Timeout-Ms`
 * header this SDK sends (see ClientOptions::$requestTimeout) actually has any effect on a
 * real LiveKit deployment. Twirp itself defines no such header, and it is currently sent on
 * a best-effort, unverified basis -- see the "Timeouts" section of README.md.
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

        // Everything from here on -- including the assertions -- runs inside the
        // try, so a failed assertion still reaches the finally and the room does
        // not leak on a real project. $bodySucceeded distinguishes "the try block
        // threw and deleteRoom() also threw" (don't let the cleanup failure mask
        // the original, more informative failure) from "the try block was fine
        // but cleanup itself failed" (that failure IS the thing to report).
        $bodySucceeded = false;

        try {
            self::assertInstanceOf(Room::class, $room);
            self::assertSame($name, $room->getName());

            $names = array_map(
                static fn (Room $r): string => $r->getName(),
                $this->livekit->room->listRooms()
            );

            self::assertContains($name, $names);

            $bodySucceeded = true;
        } finally {
            try {
                $this->livekit->room->deleteRoom($name);
            } catch (\Throwable $cleanupError) {
                if ($bodySucceeded) {
                    // No earlier failure in flight: this IS the failure.
                    throw $cleanupError;
                }

                // An assertion or RPC failure from the try block is already
                // propagating out of this finally. Don't replace it with the
                // cleanup failure -- PHP would otherwise discard the original,
                // more informative one. Still surface the leaked room rather
                // than swallowing the cleanup failure silently.
                fwrite(STDERR, sprintf(
                    "Warning: failed to clean up room \"%s\" after an earlier test failure: %s%s",
                    $name,
                    $cleanupError->getMessage(),
                    PHP_EOL
                ));
            }
        }

        $remaining = array_map(
            static fn (Room $r): string => $r->getName(),
            $this->livekit->room->listRooms()
        );

        self::assertNotContains($name, $remaining);
    }
}
