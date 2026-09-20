<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\ListUpdate;
use LiveKit\Proto\SIPMediaConfig;

/**
 * Fields for SipClient::updateSipInboundTrunkFields().
 *
 * Maps onto livekit.SIPInboundTrunkUpdate, the 'update' arm of the oneof in
 * livekit.UpdateSIPInboundTrunkRequest. A null property leaves the field untouched.
 */
final readonly class SipInboundTrunkUpdateOptions
{
    /**
     * @param int|null $mediaEncryption a LiveKit\Proto\SIPMediaEncryption constant; deprecated upstream in favour of $media->getEncryption()
     */
    public function __construct(
        public ?ListUpdate $numbers = null,
        public ?ListUpdate $allowedAddresses = null,
        public ?ListUpdate $allowedNumbers = null,
        public ?string $authUsername = null,
        public ?string $authPassword = null,
        public ?string $authRealm = null,
        public ?string $name = null,
        public ?string $metadata = null,
        public ?int $mediaEncryption = null,
        public ?SIPMediaConfig $media = null,
    ) {
    }
}
