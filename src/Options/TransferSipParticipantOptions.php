<?php

declare(strict_types=1);

namespace LiveKit\Options;

/**
 * Options for SipClient::transferSipParticipant().
 *
 * Maps onto livekit.TransferSIPParticipantRequest.
 */
final readonly class TransferSipParticipantOptions
{
    /**
     * @param bool|null                  $playDialtone   play a dialtone to the SIP participant while transferring
     * @param array<string, string>|null $headers        added to the REFER SIP request
     * @param int|null                   $ringingTimeout seconds the transfer destination has to answer
     * @param int|null                   $timeout        HTTP request timeout in seconds. NOT a proto field: it is a
     *                                                   transport-level knob. See SipClient::dialRequestTimeout().
     */
    public function __construct(
        public ?bool $playDialtone = null,
        public ?array $headers = null,
        public ?int $ringingTimeout = null,
        public ?int $timeout = null,
    ) {
    }
}
