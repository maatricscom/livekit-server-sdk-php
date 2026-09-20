<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\SIPMediaConfig;

/**
 * Options for SipClient::createSipParticipant().
 *
 * Maps onto livekit.CreateSIPParticipantRequest. Note the two Node-SDK name remappings:
 * $fromNumber is the proto field sip_number, and the number to dial is the positional
 * $number argument of the method, which becomes the proto field sip_call_to.
 */
final readonly class CreateSipParticipantOptions
{
    /**
     * @param string|null                $fromNumber            SIP From number; empty means the trunk number (proto: sip_number)
     * @param string|null                $participantIdentity   defaults to 'sip-participant' when null
     * @param string|null                $toUserOverride        replaces the user part of the To header only
     * @param array<string, string>|null $participantAttributes attached to the created participant
     * @param string|null                $dtmf                  extension digits; 'w' adds a 0.5s delay
     * @param bool|null                  $playRingtone          deprecated upstream; $playDialtone wins when both are set
     * @param array<string, string>|null $headers               SIP X-* headers sent as-is on the INVITE
     * @param int|null                   $includeHeaders        a LiveKit\Proto\SIPHeaderOptions constant
     * @param int|null                   $ringingTimeout        seconds the callee has to answer
     * @param int|null                   $maxCallDuration       seconds
     * @param int|null                   $mediaEncryption       a LiveKit\Proto\SIPMediaEncryption constant; deprecated upstream in favour of $media->getEncryption()
     * @param int|null                   $timeout               HTTP request timeout in seconds. NOT a proto field: it is a
     *                                                          transport-level knob. See SipClient::dialRequestTimeout().
     */
    public function __construct(
        public ?string $fromNumber = null,
        public ?string $participantIdentity = null,
        public ?string $participantName = null,
        public ?string $displayName = null,
        public ?string $participantMetadata = null,
        public ?array $participantAttributes = null,
        public ?string $toUserOverride = null,
        public ?string $dtmf = null,
        public ?bool $playDialtone = null,
        public ?bool $playRingtone = null,
        public ?array $headers = null,
        public ?int $includeHeaders = null,
        public ?bool $hidePhoneNumber = null,
        public ?int $ringingTimeout = null,
        public ?int $maxCallDuration = null,
        public ?bool $krispEnabled = null,
        public ?bool $waitUntilAnswered = null,
        public ?int $mediaEncryption = null,
        public ?SIPMediaConfig $media = null,
        public ?int $timeout = null,
    ) {
    }
}
