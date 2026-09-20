<?php

declare(strict_types=1);

namespace LiveKit\Options;

/**
 * Maps to livekit.SendDataRequest.
 *
 * The proto still carries the deprecated `destination_sids` field; this SDK deliberately does not
 * expose it. Use identities.
 */
final readonly class SendDataOptions
{
    /**
     * @param list<string>|null $destinationIdentities livekit.SendDataRequest.destination_identities
     * @param string|null       $topic                 livekit.SendDataRequest.topic (optional in the proto)
     * @param string|null       $nonce                 livekit.SendDataRequest.nonce (raw bytes). Leave this null:
     *                                                 the client generates a fresh 16-byte nonce per call, as the
     *                                                 proto asks it to. Supply one only to retry a send whose
     *                                                 outcome is unknown, so the server can recognise the retry
     *                                                 as a duplicate instead of delivering the message twice.
     */
    public function __construct(
        public ?array $destinationIdentities = null,
        public ?string $topic = null,
        public ?string $nonce = null,
    ) {
    }
}
