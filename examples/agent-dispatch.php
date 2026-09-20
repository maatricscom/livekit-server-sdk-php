<?php

declare(strict_types=1);

/**
 * Dispatches an agent into a room, finds it, then removes the dispatch.
 *
 *   LIVEKIT_URL=https://my-project.livekit.cloud \
 *   LIVEKIT_API_KEY=... \
 *   LIVEKIT_API_SECRET=... \
 *   AGENT_NAME=my-agent \
 *   php examples/agent-dispatch.php
 *
 * Explicit dispatch is for agents registered with a name. An agent worker that
 * registered without one is dispatched automatically to every new room and is
 * not addressable here — if nothing happens when you run this, that is usually
 * why. Dispatching a name no worker has registered is harmless: the request is
 * recorded and never assigned.
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\Exceptions\LiveKitException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Proto\AgentDispatch;

$agentName = getenv('AGENT_NAME');

if (! is_string($agentName) || $agentName === '') {
    fwrite(STDERR, 'AGENT_NAME is not set.' . PHP_EOL);
    exit(1);
}

$room = 'php-sdk-example-' . bin2hex(random_bytes(4));

try {
    $livekit = new LiveKitAPI();

    $livekit->room->createRoom(new CreateRoomOptions(name: $room, emptyTimeout: 60));
    echo "Created room \"{$room}\"" . PHP_EOL;

    echo "Dispatching \"{$agentName}\"..." . PHP_EOL;

    // metadata reaches the agent as job metadata, which is how you hand it the
    // context it needs — which customer, which language, which prompt.
    $dispatch = $livekit->agentDispatch->createDispatch(
        $room,
        $agentName,
        new CreateDispatchOptions(metadata: json_encode(['locale' => 'tr'], JSON_THROW_ON_ERROR)),
    );

    printf('  dispatch %s%s', $dispatch->getId(), PHP_EOL);

    echo 'Dispatches in this room:' . PHP_EOL;

    foreach ($livekit->agentDispatch->listDispatch($room) as $one) {
        printf('  %s  agent=%s%s', $one->getId(), $one->getAgentName(), PHP_EOL);
    }

    // getDispatch() is this SDK's own convenience: livekit.AgentDispatchService
    // has no GetDispatch rpc, so it is ListDispatch filtered by id, returning
    // the dispatch or null rather than an array you have to index.
    $found = $livekit->agentDispatch->getDispatch(dispatchId: $dispatch->getId(), room: $room);

    printf('  getDispatch: %s%s', $found instanceof AgentDispatch ? 'found' : 'gone', PHP_EOL);

    echo 'Cleaning up...' . PHP_EOL;
    $livekit->agentDispatch->deleteDispatch(dispatchId: $dispatch->getId(), room: $room);
    $livekit->room->deleteRoom($room);
    echo '  done' . PHP_EOL;
} catch (LiveKitException $e) {
    fwrite(STDERR, sprintf('LiveKit rejected the request: %s%s', $e->getMessage(), PHP_EOL));
    exit(1);
}
