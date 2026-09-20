<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

/**
 * The LiveKit SIP service: trunks, dispatch rules and SIP participants.
 *
 * Covers the 16 live RPCs of livekit.SIP plus the three partial-update convenience
 * wrappers. CreateSIPTrunk is absent on purpose: it is commented out and marked DELETED
 * in livekit_sip.proto at protocol v1.52.0, so its Twirp route no longer exists.
 */
interface SipClientInterface
{
}
