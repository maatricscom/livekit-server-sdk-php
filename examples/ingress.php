<?php

declare(strict_types=1);

/**
 * Creates an RTMP ingress, prints the URL and stream key to point an encoder at,
 * then updates and deletes it.
 *
 *   LIVEKIT_URL=https://my-project.livekit.cloud \
 *   LIVEKIT_API_KEY=... \
 *   LIVEKIT_API_SECRET=... \
 *   ROOM=my-room \
 *   php examples/ingress.php
 *
 * An ingress nobody streams to costs nothing and can be deleted again, which is
 * what makes this the one service besides rooms whose whole lifecycle is safe to
 * run against a real project.
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\Exceptions\LiveKitException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\ListIngressOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Proto\IngressInput;

$room = getenv('ROOM');

if (! is_string($room) || $room === '') {
    fwrite(STDERR, 'ROOM is not set.' . PHP_EOL);
    exit(1);
}

try {
    $livekit = new LiveKitAPI();

    echo 'Creating an RTMP ingress...' . PHP_EOL;

    $ingress = $livekit->ingress->createIngress(new CreateIngressOptions(
        inputType: IngressInput::RTMP_INPUT,
        name: 'php-sdk-example',
        roomName: $room,
        participantIdentity: 'rtmp-source',
        participantName: 'RTMP source',
        // WHIP_INPUT can skip transcoding and forward the incoming media as-is;
        // RTMP always transcodes, so leaving this unset is the same as true here.
        enableTranscoding: true,
    ));

    printf('  id:        %s%s', $ingress->getIngressId(), PHP_EOL);
    printf('  url:       %s%s', $ingress->getUrl(), PHP_EOL);
    printf('  stream key: %s%s', $ingress->getStreamKey(), PHP_EOL);
    echo '  point OBS or ffmpeg at that url with that key' . PHP_EOL;

    // Only the name is sent. Everything omitted keeps its current value rather
    // than being cleared, which is why UpdateIngressOptions takes nullables.
    echo 'Renaming it...' . PHP_EOL;

    $updated = $livekit->ingress->updateIngress(
        $ingress->getIngressId(),
        new UpdateIngressOptions(name: 'php-sdk-example-renamed'),
    );

    printf('  name=%s  room=%s (unchanged)%s', $updated->getName(), $updated->getRoomName(), PHP_EOL);

    echo 'Ingresses on this room:' . PHP_EOL;

    foreach ($livekit->ingress->listIngress(new ListIngressOptions(roomName: $room)) as $info) {
        printf('  %s  %s  state=%s%s', $info->getIngressId(), $info->getName(), $info->getState()?->getStatus() ?? 0, PHP_EOL);
    }

    echo 'Deleting...' . PHP_EOL;
    $livekit->ingress->deleteIngress($ingress->getIngressId());
    echo '  done' . PHP_EOL;
} catch (LiveKitException $e) {
    fwrite(STDERR, sprintf('LiveKit rejected the request: %s%s', $e->getMessage(), PHP_EOL));
    exit(1);
}
