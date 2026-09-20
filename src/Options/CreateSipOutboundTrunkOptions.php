<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\SIPMediaConfig;
use LiveKit\Proto\SIPTransport;

/**
 * Options for SipClient::createSipOutboundTrunk().
 *
 * Maps onto livekit.SIPOutboundTrunkInfo. The trunk's name, address and numbers are
 * positional arguments of the method, mirroring the Node SDK.
 */
final readonly class CreateSipOutboundTrunkOptions
{
    /**
     * @param int                        $transport            a LiveKit\Proto\SIPTransport constant
     * @param string|null                $destinationCountry   ISO 3166-1 alpha-2
     * @param array<string, string>|null $headers              SIP X-* headers to include in the INVITE
     * @param array<string, string>|null $headersToAttributes  SIP X-* header name => participant attribute name
     * @param array<string, string>|null $attributesToHeaders  participant attribute name => SIP X-* header name
     * @param int|null                   $includeHeaders       a LiveKit\Proto\SIPHeaderOptions constant
     * @param int|null                   $mediaEncryption      a LiveKit\Proto\SIPMediaEncryption constant; deprecated upstream in favour of $media->getEncryption()
     * @param string|null                $fromHost             custom host for the 'From' SIP header
     */
    public function __construct(
        public int $transport = SIPTransport::SIP_TRANSPORT_AUTO,
        public ?string $metadata = null,
        public ?string $destinationCountry = null,
        public ?string $authUsername = null,
        public ?string $authPassword = null,
        public ?array $headers = null,
        public ?array $headersToAttributes = null,
        public ?array $attributesToHeaders = null,
        public ?int $includeHeaders = null,
        public ?int $mediaEncryption = null,
        public ?SIPMediaConfig $media = null,
        public ?string $fromHost = null,
    ) {
    }
}
