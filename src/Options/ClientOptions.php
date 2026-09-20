<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Enums\WireFormat;
use LiveKit\Http\Failover;

/**
 * Transport-level options shared by every service client.
 *
 * Unlike its neighbours here, this is passed once when a client is built rather
 * than to an individual call — it configures how the SDK talks to LiveKit, not
 * what any one request asks for.
 */
final readonly class ClientOptions
{
    /**
     * @param int         $requestTimeout Seconds. Sent as the X-Twirp-Timeout-Ms header, a hint that
     *                                    LiveKit may honour -- Twirp itself defines no such header, so
     *                                    this is not a guarantee. PSR-18 has no per-request timeout
     *                                    either, so configure your own HTTP client for an actual
     *                                    client-side deadline.
     * @param string      $prefix         Twirp path prefix; LiveKit uses '/twirp'.
     * @param string|null $token          A pre-signed token used verbatim instead of
     *                                    minting one per call. Lets the SDK run without a secret.
     * @param bool        $failover       Retry a failed request against another LiveKit Cloud region.
     *                                    On a transport error or an HTTP 5xx the client asks the host
     *                                    for its region list and replays the request against the next
     *                                    one, up to Failover::MAX_ATTEMPTS times, with exponential
     *                                    backoff. A 4xx is returned immediately. This only ever engages
     *                                    for *.livekit.cloud hosts -- a replay sends your token to an
     *                                    origin learned from a server response, so the hosts that can
     *                                    receive it stay pinned to a domain LiveKit controls. Set false
     *                                    to disable, or if you would rather handle retries yourself.
     * @param bool        $failoverForce  Internal, for this SDK's own tests: skips the *.livekit.cloud
     *                                    check so failover can be exercised against a local mock. It
     *                                    removes the guarantee described above. Do not set it.
     * @param int         $failoverBackoffMs Internal, for this SDK's own tests: shortens the retry
     *                                    backoff. Do not set it.
     */
    public function __construct(
        public int $requestTimeout = 10,
        public string $prefix = '/twirp',
        public ?string $token = null,
        public WireFormat $wireFormat = WireFormat::Protobuf,
        public bool $failover = true,
        public bool $failoverForce = false,
        public int $failoverBackoffMs = Failover::BACKOFF_BASE_MS,
    ) {
    }
}
