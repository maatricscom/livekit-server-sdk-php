<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPOutboundTrunkInfo;

/**
 * The LiveKit SIP service: trunks, dispatch rules and SIP participants.
 *
 * Covers the 16 live RPCs of livekit.SIP plus the three partial-update convenience
 * wrappers. CreateSIPTrunk is absent on purpose: it is commented out and marked DELETED
 * in livekit_sip.proto at protocol v1.52.0, so its Twirp route no longer exists.
 */
interface SipClientInterface
{
    /**
     * Creates a SIP inbound trunk.
     *
     * @param list<string> $numbers phone numbers this trunk accepts calls for
     */
    public function createSipInboundTrunk(
        string $name,
        array $numbers,
        ?CreateSipInboundTrunkOptions $opts = null,
    ): SIPInboundTrunkInfo;

    /**
     * Creates a SIP outbound trunk.
     *
     * @param string       $address hostname or IP the INVITE is sent to, with no 'sip:' prefix
     * @param list<string> $numbers numbers to place calls from; one is picked at random
     */
    public function createSipOutboundTrunk(
        string $name,
        string $address,
        array $numbers,
        ?CreateSipOutboundTrunkOptions $opts = null,
    ): SIPOutboundTrunkInfo;
}
