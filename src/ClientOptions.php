<?php

declare(strict_types=1);

namespace LiveKit;

use LiveKit\Enums\WireFormat;

/**
 * Transport-level options shared by every service client.
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
     */
    public function __construct(
        public int $requestTimeout = 10,
        public string $prefix = '/twirp',
        public ?string $token = null,
        public WireFormat $wireFormat = WireFormat::Protobuf,
    ) {
    }
}
