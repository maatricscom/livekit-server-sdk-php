<?php

declare(strict_types=1);

/**
 * Bridges a WhatsApp call into a LiveKit room.
 *
 *   LIVEKIT_URL=https://my-project.livekit.cloud \
 *   LIVEKIT_API_KEY=... LIVEKIT_API_SECRET=... \
 *   META_PHONE_NUMBER_ID=... META_API_KEY=... \
 *   TO_NUMBER=+15551234567 \
 *   php examples/connector.php
 *
 * LiveKit Cloud only — the open-source server does not implement livekit.Connector.
 *
 * The flow is not one script, and that is the thing worth understanding here:
 *
 *   1. dialWhatsAppCall()      you place the call; Meta starts ringing
 *   2. ...Meta calls YOUR webhook when the callee answers, with their SDP
 *   3. connectWhatsAppCall()   you hand that SDP back to finish the handshake
 *   4. disconnectWhatsAppCall()
 *
 * Step 2 happens in a different request, in whatever endpoint you registered
 * with Meta, so steps 1 and 3 cannot sit next to each other in real code.
 * completeHandshake() below is written as the function that webhook would call.
 *
 * An inbound call is the mirror image: Meta's webhook arrives first with the
 * caller's offer, and acceptWhatsAppCall() takes it.
 *
 * Step 1 rings a real telephone, so it is gated:
 *
 *   PLACE_A_REAL_WHATSAPP_CALL=yes
 *
 * An example is something people run to find out what it does, and finding out
 * should not cost someone a phone call.
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\Exceptions\LiveKitException;
use LiveKit\LiveKitAPI;
use LiveKit\Options\ConnectWhatsAppCallOptions;
use LiveKit\Options\DialWhatsAppCallOptions;
use LiveKit\Proto\ConnectWhatsAppCallResponse;
use LiveKit\Proto\SessionDescription;

function env(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        fwrite(STDERR, sprintf('%s is not set.%s', $name, PHP_EOL));
        exit(1);
    }

    return $value;
}

/**
 * What the Meta webhook handler does once the callee picks up.
 *
 * $sdpFromMeta is the `sdp` string out of the webhook body; the type is
 * 'answer' for a call you placed.
 */
function completeHandshake(LiveKitAPI $livekit, string $callId, string $sdpFromMeta): ConnectWhatsAppCallResponse
{
    $sdp = new SessionDescription()
        ->setType('answer')
        ->setSdp($sdpFromMeta);

    // waitUntilAnswered holds the request open past the ring window, so the SDK
    // raises its own request timeout for this call rather than giving up while
    // the phone is still ringing.
    return $livekit->connector->connectWhatsAppCall(
        $callId,
        $sdp,
        new ConnectWhatsAppCallOptions(waitUntilAnswered: true, timeout: 45),
    );
}

$phoneNumberId = env('META_PHONE_NUMBER_ID');
$apiKey = env('META_API_KEY');
$to = env('TO_NUMBER');
$room = 'php-sdk-example-' . bin2hex(random_bytes(4));

if (getenv('PLACE_A_REAL_WHATSAPP_CALL') !== 'yes') {
    echo "Would dial {$to} over WhatsApp into room \"{$room}\"." . PHP_EOL;
    echo 'Set PLACE_A_REAL_WHATSAPP_CALL=yes to actually place it.' . PHP_EOL;
    exit(0);
}

try {
    $livekit = new LiveKitAPI();

    echo "Dialling {$to}..." . PHP_EOL;

    $dialled = $livekit->connector->dialWhatsAppCall(
        $phoneNumberId,
        $to,
        $apiKey,
        // Meta's Cloud API version, WITHOUT the "v" the Graph path carries: the
        // path is /v23.0/{id}/calls, but this field wants "23.0". Sending
        // "v23.0" is refused with invalid_argument, "whatsapp cloud api version
        // not supported" -- as is any version LiveKit has not allow-listed.
        '23.0',
        new DialWhatsAppCallOptions(
            roomName: $room,
            participantIdentity: 'whatsapp-caller',
            participantName: 'WhatsApp caller',
            ringingTimeout: 30,
        ),
    );

    $callId = $dialled->getWhatsappCallId();

    printf('  call %s, room %s%s', $callId, $dialled->getRoomName(), PHP_EOL);
    echo '  now waiting for Meta to post the answer to your webhook, which should' . PHP_EOL;
    echo '  call completeHandshake($livekit, $callId, $sdp) above.' . PHP_EOL;
    echo PHP_EOL;
    echo "  to hang up: \$livekit->connector->disconnectWhatsAppCall('{$callId}', \$apiKey);" . PHP_EOL;
} catch (LiveKitException $e) {
    fwrite(STDERR, sprintf('LiveKit rejected the request: %s%s', $e->getMessage(), PHP_EOL));
    exit(1);
}
