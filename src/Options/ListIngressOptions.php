<?php

declare(strict_types=1);

namespace LiveKit\Options;

/**
 * Maps to livekit.ListIngressRequest. `pageToken` is wrapped into the
 * livekit.TokenPagination message the proto declares for `page_token`.
 */
final readonly class ListIngressOptions
{
    public function __construct(
        public ?string $roomName = null,
        public ?string $ingressId = null,
        public ?string $pageToken = null,
    ) {
    }
}
