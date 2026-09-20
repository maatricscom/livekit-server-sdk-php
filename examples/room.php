<?php

declare(strict_types=1);

/**
 * Creates a room, lists rooms, then deletes the one it created.
 *
 * Run it against a real LiveKit deployment with:
 *
 *   LIVEKIT_URL=https://my-project.livekit.cloud \
 *   LIVEKIT_API_KEY=... \
 *   LIVEKIT_API_SECRET=... \
 *   php examples/room.php
 *
 * LiveKitClient() with no arguments reads all three from the environment.
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\Exceptions\LiveKitException;
use LiveKit\LiveKitClient;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Proto\Room;

$roomName = 'php-sdk-example-' . bin2hex(random_bytes(4));

try {
    $livekit = new LiveKitClient();

    echo "Creating room \"{$roomName}\"..." . PHP_EOL;
    $room = $livekit->room->createRoom(new CreateRoomOptions(
        name: $roomName,
        emptyTimeout: 300,
        maxParticipants: 10,
    ));
    echo "Created: {$room->getName()} (sid: {$room->getSid()})" . PHP_EOL;

    echo PHP_EOL . 'Rooms on this project:' . PHP_EOL;
    foreach ($livekit->room->listRooms() as $listed) {
        /** @var Room $listed */
        echo " - {$listed->getName()} ({$listed->getNumParticipants()} participant(s))" . PHP_EOL;
    }

    echo PHP_EOL . "Deleting room \"{$roomName}\"..." . PHP_EOL;
    $livekit->room->deleteRoom($roomName);
    echo 'Deleted.' . PHP_EOL;
} catch (LiveKitException $e) {
    fwrite(STDERR, 'Room operation failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
