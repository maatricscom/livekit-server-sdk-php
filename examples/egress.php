<?php

declare(strict_types=1);

/**
 * Records a room to S3, then stops the recording.
 *
 *   LIVEKIT_URL=https://my-project.livekit.cloud \
 *   LIVEKIT_API_KEY=... \
 *   LIVEKIT_API_SECRET=... \
 *   AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... \
 *   EGRESS_BUCKET=my-recordings EGRESS_REGION=eu-central-1 \
 *   ROOM=my-room \
 *   php examples/egress.php
 *
 * This one costs money and needs somewhere to write, which is why it asks for a
 * bucket rather than inventing one. LiveKit uploads directly from its own
 * servers, so the credentials below go to LiveKit, not to your process — they
 * travel inside the request and are never written into an access token. Minting
 * a token that carried them would publish them to whoever holds the token, and
 * AccessToken refuses to sign one that does.
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\Exceptions\LiveKitException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\RoomCompositeOptions;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\EncodedFileType;
use LiveKit\Proto\S3Upload;

function env(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        fwrite(STDERR, sprintf('%s is not set.%s', $name, PHP_EOL));
        exit(1);
    }

    return $value;
}

$room = env('ROOM');

$s3 = (new S3Upload())
    ->setAccessKey(env('AWS_ACCESS_KEY_ID'))
    ->setSecret(env('AWS_SECRET_ACCESS_KEY'))
    ->setBucket(env('EGRESS_BUCKET'))
    ->setRegion(env('EGRESS_REGION'));

$file = (new EncodedFileOutput())
    ->setFileType(EncodedFileType::MP4)
    ->setFilepath(sprintf('%s-{time}.mp4', $room))
    ->setS3($s3);

try {
    $livekit = new LiveKitAPI();

    // EncodedOutputs fills the plural *_outputs arrays and leaves the deprecated
    // singular oneof unset. Passing the EncodedFileOutput on its own would fill
    // both, for servers old enough to need it.
    echo "Starting a composite recording of \"{$room}\"..." . PHP_EOL;

    $egress = $livekit->egress->startRoomCompositeEgress(
        $room,
        new EncodedOutputs(file: $file),
        new RoomCompositeOptions(layout: 'speaker'),
    );

    printf('  egress %s is %s%s', $egress->getEgressId(), $egress->getStatus(), PHP_EOL);

    echo 'Active egresses:' . PHP_EOL;

    foreach ($livekit->egress->listEgress() as $info) {
        printf('  %s  room=%s  status=%s%s', $info->getEgressId(), $info->getRoomName(), $info->getStatus(), PHP_EOL);
    }

    echo "Stopping {$egress->getEgressId()}..." . PHP_EOL;
    $stopped = $livekit->egress->stopEgress($egress->getEgressId());

    printf('  now %s%s', $stopped->getStatus(), PHP_EOL);
} catch (LiveKitException $e) {
    fwrite(STDERR, sprintf('LiveKit rejected the request: %s%s', $e->getMessage(), PHP_EOL));
    exit(1);
}
