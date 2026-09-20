<?php

declare(strict_types=1);

namespace LiveKit\Http;

/**
 * Request timeouts for the calls that wait on a ringing phone.
 *
 * Most RPCs answer in milliseconds. A handful do not: they hold the request open
 * until the far end picks up, so the request has to outlast the ring window or it
 * aborts before the call can be answered. That applies to SIP's
 * CreateSIPParticipant and TransferSIPParticipant, and to the Connector's
 * AcceptWhatsAppCall and ConnectWhatsAppCall.
 *
 * Shared rather than living on SipClient, because a WhatsApp client having to
 * reach into the SIP client for its timing constants would be a strange way to
 * express that they ring the same way.
 */
final class DialTimeout
{
    /**
     * Ring window assumed when a dialing request does not set one; matches the
     * server default. Pinned explicitly so our request timeout does not silently
     * change if the server default does.
     */
    public const DEFAULT_RINGING_TIMEOUT_SECONDS = 30;

    /** Margin kept between the ring window and the HTTP request timeout. */
    public const RINGING_TIMEOUT_MARGIN_SECONDS = 2;

    /**
     * The request timeout, in seconds, for a call that waits on an answer.
     *
     * The floor is the ring window plus a margin. A longer caller-supplied timeout
     * is honoured; a shorter one is raised to the floor, because a timeout below
     * the ring window can only ever cut off a call that was still ringing.
     */
    public static function requestTimeout(?int $timeout, ?int $ringingTimeout): int
    {
        // A ring window is a duration; a negative one is a caller mistake, and
        // passing it through would produce a negative request timeout that says
        // something false to the server. Treated as "no wait" instead.
        $ring = max(0, $ringingTimeout ?? self::DEFAULT_RINGING_TIMEOUT_SECONDS);
        $floor = $ring + self::RINGING_TIMEOUT_MARGIN_SECONDS;

        return max($timeout ?? $floor, $floor);
    }
}
