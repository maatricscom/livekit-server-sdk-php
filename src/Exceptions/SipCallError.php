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

        // getMeta() values are always strings (Twirp meta is a string map), so a
        // malformed or unexpected non-numeric value must not silently become 0 --
        // that would be indistinguishable from a real (if invalid) status of 0.
        return is_string($code) && ctype_digit($code) ? (int) $code : null;
    }

    public function getSipStatus(): ?string
    {
        return $this->getMeta()['sip_status'] ?? null;
    }
}
