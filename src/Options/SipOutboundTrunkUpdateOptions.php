<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\ListUpdate;
use LiveKit\Proto\SIPMediaConfig;

/**
 * Fields for SipClient::updateSipOutboundTrunkFields().
 *
 * Maps onto livekit.SIPOutboundTrunkUpdate, the 'update' arm of the oneof in
 * livekit.UpdateSIPOutboundTrunkRequest. A null property leaves the field untouched.
 *
 * Note: the Node SDK's SipOutboundTrunkUpdateOptions also declares allowedAddresses and
 * allowedNumbers. livekit.SIPOutboundTrunkUpdate has no such fields - those two belong to the
 * inbound update message - so anything set there is silently dropped on the wire. They are
 * deliberately absent here.
 */
final readonly class SipOutboundTrunkUpdateOptions
{
    /**
     * @param int|null $transport       a LiveKit\Proto\SIPTransport constant
     * @param int|null $mediaEncryption a LiveKit\Proto\SIPMediaEncryption constant; deprecated upstream in favour of $media->getEncryption()
     */
    public function __construct(
        public ?string $address = null,
        public ?int $transport = null,
        public ?string $destinationCountry = null,
        public ?ListUpdate $numbers = null,
        public ?string $authUsername = null,
        public ?string $authPassword = null,
        public ?string $name = null,
        public ?string $metadata = null,
        public ?int $mediaEncryption = null,
        public ?SIPMediaConfig $media = null,
        public ?string $fromHost = null,
    ) {
    }
}
