<?php

declare(strict_types=1);

/**
 * Mints a LiveKit access token for a participant joining a room.
 *
 * Reads credentials from the environment and prints a JWT you can hand to a
 * client SDK (JavaScript, Swift, Android, ...) to connect. Run it with:
 *
 *   LIVEKIT_API_KEY=... LIVEKIT_API_SECRET=... php examples/token.php
 *
 * Optionally set ROOM_NAME and PARTICIPANT_IDENTITY to override the defaults
 * below. No network call is made -- token minting is a purely local HS256
 * signing operation, so this script does not need LIVEKIT_URL or a running
 * server.
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\AccessToken;
use LiveKit\AccessTokenOptions;
use LiveKit\Exceptions\LiveKitException;
use LiveKit\Grants\VideoGrant;

$roomName = getenv('ROOM_NAME') ?: 'my-room';
$identity = getenv('PARTICIPANT_IDENTITY') ?: 'alice';

try {
    // AccessToken() falls back to LIVEKIT_API_KEY / LIVEKIT_API_SECRET when the
    // first two constructor arguments are omitted, so this reads credentials
    // from the environment automatically.
    $token = new AccessToken(options: new AccessTokenOptions(
        identity: $identity,
        name: $identity,
        ttl: '6h',
    ));

    // roomJoin grants the ability to connect. canPublish/canSubscribe are left
    // unset (null) here deliberately: the server default already allows both,
    // so this participant can publish and subscribe. Pass `false` explicitly
    // for either one if this token should NOT be allowed to do it -- leaving
    // it null does not deny it.
    $token->addGrant(new VideoGrant(roomJoin: true, room: $roomName));

    $jwt = $token->toJwt();

    echo "Access token for \"{$identity}\" joining \"{$roomName}\":" . PHP_EOL;
    echo $jwt . PHP_EOL;
} catch (LiveKitException $e) {
    fwrite(STDERR, 'Could not mint token: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
