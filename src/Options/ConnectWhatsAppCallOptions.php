<?php

declare(strict_types=1);

namespace LiveKit\Options;

/**
 * Options for ConnectorClient::connectWhatsAppCall().
 *
 * Maps onto livekit.ConnectWhatsAppCallRequest, whose only optional field is the
 * wait. The Node SDK does not expose it; the proto does, and it changes how long
 * the request may block, so it is exposed here with the timeout that goes with it.
 */
final readonly class ConnectWhatsAppCallOptions
{
    /**
     * @param bool|null $waitUntilAnswered hold the request open until the callee joins the room
     * @param int|null  $timeout           HTTP request timeout in seconds. NOT a proto field. Ignored
     *                                     unless $waitUntilAnswered is set, and raised to the ring
     *                                     window plus a margin when it is. See LiveKit\Http\DialTimeout.
     */
    public function __construct(
        public ?bool $waitUntilAnswered = null,
        public ?int $timeout = null,
    ) {
    }
}
