<?php

declare(strict_types=1);

/**
 * A minimal webhook receiver endpoint for LiveKit's server-to-server webhooks.
 *
 * Point a LiveKit project's webhook URL at wherever this script is served
 * from and it will verify and print each event as it arrives. Run it as a
 * standalone server with PHP's built-in web server:
 *
 *   LIVEKIT_API_KEY=... LIVEKIT_API_SECRET=... php -S localhost:8080 examples/webhook.php
 *
 * then configure that URL (through a tunnel such as ngrok if LiveKit needs to
 * reach it over the public internet) as the project's webhook endpoint in the
 * LiveKit dashboard, or send it a signed test request of your own.
 *
 * The one rule that matters here: the raw request body is read with
 * file_get_contents('php://input') and passed to receive() completely
 * unmodified. Never json_decode() it and re-encode before verifying -- the
 * signature covers the exact bytes LiveKit sent, and PHP's json_encode() does
 * not reproduce them byte-for-byte (see README.md's Webhooks section for why).
 */

require __DIR__ . '/../vendor/autoload.php';

use LiveKit\Exceptions\WebhookVerificationException;
use LiveKit\WebhookReceiver;

$rawBody = file_get_contents('php://input');
// $_SERVER carries no types, so narrow it before handing it over: a header that
// is somehow not a string is the same as no header at all.
$header = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
$authHeader = is_string($header) ? $header : null;

if ($rawBody === false) {
    http_response_code(400);
    echo 'Could not read request body.' . PHP_EOL;
    exit(1);
}

try {
    $receiver = new WebhookReceiver();
    $event = $receiver->receive($rawBody, $authHeader);
} catch (WebhookVerificationException $e) {
    http_response_code(401);
    echo 'Webhook verification failed: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

http_response_code(200);

$room = $event->getRoom();
$participant = $event->getParticipant();

echo 'Received event: ' . $event->getEvent() . PHP_EOL;
echo 'Event id: ' . $event->getId() . PHP_EOL;

if ($room !== null) {
    echo 'Room: ' . $room->getName() . ' (sid: ' . $room->getSid() . ')' . PHP_EOL;
}

if ($participant !== null) {
    echo 'Participant: ' . $participant->getIdentity() . PHP_EOL;
}
