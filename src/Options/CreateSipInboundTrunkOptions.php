<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\SIPMediaConfig;

/**
 * Options for SipClient::createSipInboundTrunk().
 *
 * Maps onto livekit.SIPInboundTrunkInfo. The trunk's name and numbers are positional
 * arguments of the method, mirroring the Node SDK.
 */
final readonly class CreateSipInboundTrunkOptions
{
    /**
     * @param list<string>|null          $allowedAddresses     CIDRs or IPs traffic is accepted from (empty = all)
     * @param list<string>|null          $allowedNumbers       numbers allowed to call this trunk (empty = all)
     * @param array<string, string>|null $headers              SIP X-* headers to include in 200 OK responses
     * @param array<string, string>|null $headersToAttributes  SIP X-* header name => participant attribute name
     * @param array<string, string>|null $attributesToHeaders  participant attribute name => SIP X-* header name
     * @param int|null                   $includeHeaders       a LiveKit\Proto\SIPHeaderOptions constant
     * @param int|null                   $mediaEncryption      a LiveKit\Proto\SIPMediaEncryption constant; deprecated upstream in favour of $media->getEncryption()
     * @param int|null                   $ringingTimeout       seconds
     * @param int|null                   $maxCallDuration      seconds
     */
    public function __construct(
        public ?string $metadata = null,
        public ?array $allowedAddresses = null,
        public ?array $allowedNumbers = null,
        public ?string $authUsername = null,
        public ?string $authPassword = null,
        public ?string $authRealm = null,
        public ?array $headers = null,
        public ?array $headersToAttributes = null,
        public ?array $attributesToHeaders = null,
        public ?int $includeHeaders = null,
        public ?bool $krispEnabled = null,
        public ?int $mediaEncryption = null,
        public ?SIPMediaConfig $media = null,
        public ?int $ringingTimeout = null,
        public ?int $maxCallDuration = null,
    ) {
    }
}
