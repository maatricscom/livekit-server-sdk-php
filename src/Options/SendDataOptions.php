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
     * @param string|null       $nonce                 livekit.SendDataRequest.nonce (raw bytes, for de-duping)
     */
    public function __construct(
        public ?array $destinationIdentities = null,
        public ?string $topic = null,
        public ?string $nonce = null,
    ) {
    }
}
