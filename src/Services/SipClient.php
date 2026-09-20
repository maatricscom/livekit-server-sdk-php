<?php

declare(strict_types=1);

namespace LiveKit\Services;

/**
 * Client for the LiveKit SIP API.
 *
 * Implements the 16 live RPCs of the livekit.SIP service plus the three partial-update
 * convenience wrappers of the Node SDK (updateSipDispatchRuleFields,
 * updateSipInboundTrunkFields, updateSipOutboundTrunkFields).
 *
 * There is no createSipTrunk(): at livekit/protocol v1.52.0 the RPC is commented out in
 * livekit_sip.proto and marked DELETED, so POSTing to /twirp/livekit.SIP/CreateSIPTrunk
 * would 404. The CreateSIPTrunkRequest and SIPTrunkInfo messages still exist - SIPTrunkInfo
 * is the response of the still-live DeleteSIPTrunk - which is why the generated classes are
 * there and the method is not. Use createSipInboundTrunk() or createSipOutboundTrunk().
 *
 * Only createSipParticipant() and transferSipParticipant() can fail with
 * LiveKit\Exceptions\SipCallError: they are the only RPCs whose Twirp error meta carries a
 * SIP status. Everything else fails with a plain LiveKit\Exceptions\TwirpException.
 *
 * Note: this class implements LiveKit\Contracts\SipClientInterface. The `implements`
 * clause is added once all 19 public methods exist (see the closing cycle of Task 13),
 * so that the class never fails to load with a partially-built interface.
 */
final class SipClient extends ServiceBase
{
    /** Twirp service name as it appears in the URL: /twirp/livekit.SIP/<Method>. */
    private const SERVICE = 'SIP';
}
