<?php

declare(strict_types=1);

namespace LiveKit\Exceptions;

/**
 * Raised by SipClient::createSipParticipant() and SipClient::transferSipParticipant(),
 * the only two LiveKit methods that can fail with SIP-level status information.
 */
final class SipCallError extends TwirpException
{
    public function getSipStatusCode(): ?int
    {
        $code = $this->getMeta()['sip_status_code'] ?? null;

        return is_string($code) && $code !== '' ? (int) $code : null;
    }

    public function getSipStatus(): ?string
    {
        return $this->getMeta()['sip_status'] ?? null;
    }
}
