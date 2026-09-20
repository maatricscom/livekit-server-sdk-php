<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\CreateSipDispatchRuleOptions;
use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;
use LiveKit\Options\CreateSipParticipantOptions;
use LiveKit\Options\ListSipDispatchRuleOptions;
use LiveKit\Options\ListSipTrunkOptions;
use LiveKit\Options\SipDispatchRuleUpdateOptions;
use LiveKit\Options\SipInboundTrunkUpdateOptions;
use LiveKit\Options\SipOutboundTrunkUpdateOptions;
use LiveKit\Options\TransferSipParticipantOptions;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPDispatchRuleInfo;
use LiveKit\Proto\SIPInboundTrunkInfo;
use LiveKit\Proto\SIPOutboundConfig;
use LiveKit\Proto\SIPOutboundTrunkInfo;
use LiveKit\Proto\SIPParticipantInfo;
use LiveKit\Proto\SIPTrunkInfo;
use LiveKit\Proto\TransferSIPParticipantResponse;

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

    /**
     * Replaces a SIP inbound trunk wholesale. Fields left unset on $trunk are cleared.
     * Use updateSipInboundTrunkFields() to change only some fields.
     */
    public function updateSipInboundTrunk(string $sipTrunkId, SIPInboundTrunkInfo $trunk): SIPInboundTrunkInfo;

    /**
     * Updates only the given fields of a SIP inbound trunk, leaving the rest alone.
     * Sends the 'update' arm of the oneof in livekit.UpdateSIPInboundTrunkRequest.
     */
    public function updateSipInboundTrunkFields(
        string $sipTrunkId,
        SipInboundTrunkUpdateOptions $fields,
    ): SIPInboundTrunkInfo;

    /**
     * Replaces a SIP outbound trunk wholesale. Fields left unset on $trunk are cleared.
     * Use updateSipOutboundTrunkFields() to change only some fields.
     */
    public function updateSipOutboundTrunk(string $sipTrunkId, SIPOutboundTrunkInfo $trunk): SIPOutboundTrunkInfo;

    /**
     * Updates only the given fields of a SIP outbound trunk, leaving the rest alone.
     * Sends the 'update' arm of the oneof in livekit.UpdateSIPOutboundTrunkRequest.
     */
    public function updateSipOutboundTrunkFields(
        string $sipTrunkId,
        SipOutboundTrunkUpdateOptions $fields,
    ): SIPOutboundTrunkInfo;

    /** Fetches one SIP inbound trunk, or null when the server returns no trunk. */
    public function getSipInboundTrunk(string $sipTrunkId): ?SIPInboundTrunkInfo;

    /** Fetches one SIP outbound trunk, or null when the server returns no trunk. */
    public function getSipOutboundTrunk(string $sipTrunkId): ?SIPOutboundTrunkInfo;

    /**
     * Lists SIP inbound trunks. With no filters, all trunks are listed.
     *
     * @return list<SIPInboundTrunkInfo>
     */
    public function listSipInboundTrunk(?ListSipTrunkOptions $opts = null): array;

    /**
     * Lists SIP outbound trunks. With no filters, all trunks are listed.
     *
     * @return list<SIPOutboundTrunkInfo>
     */
    public function listSipOutboundTrunk(?ListSipTrunkOptions $opts = null): array;

    /**
     * Lists legacy SIP trunks.
     *
     * @deprecated The livekit.SIP.ListSIPTrunk rpc carries `option deprecated = true`.
     *             Use listSipInboundTrunk() or listSipOutboundTrunk().
     *
     * @return list<SIPTrunkInfo>
     */
    public function listSipTrunk(): array;

    /**
     * Deletes a SIP trunk, inbound or outbound.
     *
     * The rpc returns the legacy livekit.SIPTrunkInfo shape for both trunk kinds; that
     * message is deprecated upstream but is still this rpc's response type.
     */
    public function deleteSipTrunk(string $sipTrunkId): SIPTrunkInfo;

    /**
     * Creates a SIP dispatch rule.
     *
     * @param SIPDispatchRule $rule one of dispatch_rule_direct, dispatch_rule_individual
     *                              or dispatch_rule_callee
     */
    public function createSipDispatchRule(
        SIPDispatchRule $rule,
        ?CreateSipDispatchRuleOptions $opts = null,
    ): SIPDispatchRuleInfo;

    /**
     * Replaces a SIP dispatch rule wholesale. Fields left unset on $rule are cleared.
     * Use updateSipDispatchRuleFields() to change only some fields.
     */
    public function updateSipDispatchRule(
        string $sipDispatchRuleId,
        SIPDispatchRuleInfo $rule,
    ): SIPDispatchRuleInfo;

    /**
     * Updates only the given fields of a SIP dispatch rule, leaving the rest alone.
     * Sends the 'update' arm of the oneof in livekit.UpdateSIPDispatchRuleRequest.
     */
    public function updateSipDispatchRuleFields(
        string $sipDispatchRuleId,
        SipDispatchRuleUpdateOptions $fields,
    ): SIPDispatchRuleInfo;

    /**
     * Lists SIP dispatch rules. With no filters, all rules are listed.
     *
     * @return list<SIPDispatchRuleInfo>
     */
    public function listSipDispatchRule(?ListSipDispatchRuleOptions $opts = null): array;

    /** Deletes a SIP dispatch rule and returns the rule as it was. */
    public function deleteSipDispatchRule(string $sipDispatchRuleId): SIPDispatchRuleInfo;

    /**
     * Dials a number over a SIP trunk and joins the resulting call to a room.
     *
     * @param string                 $number              number to dial (proto field sip_call_to)
     * @param SIPOutboundConfig|null $outboundTrunkConfig inline trunk config instead of a stored trunk
     *
     * @throws \LiveKit\Exceptions\SipCallError when the failure carries a SIP status
     * @throws \LiveKit\Exceptions\TwirpException
     */
    public function createSipParticipant(
        string $sipTrunkId,
        string $number,
        string $roomName,
        ?CreateSipParticipantOptions $opts = null,
        ?SIPOutboundConfig $outboundTrunkConfig = null,
    ): SIPParticipantInfo;

    /**
     * Transfers a SIP participant to another destination with a SIP REFER.
     *
     * @param string $transferTo SIP URI or tel: URI of the destination
     *
     * @throws \LiveKit\Exceptions\SipCallError when the failure carries a SIP status
     * @throws \LiveKit\Exceptions\TwirpException
     */
    public function transferSipParticipant(
        string $roomName,
        string $participantIdentity,
        string $transferTo,
        ?TransferSipParticipantOptions $opts = null,
    ): TransferSIPParticipantResponse;
}
