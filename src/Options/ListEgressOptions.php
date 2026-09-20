<?php

declare(strict_types=1);

namespace LiveKit\Options;

final readonly class ListEgressOptions
{
    public function __construct(
        public ?string $roomName = null,
        public ?string $egressId = null,
        public ?bool $active = null,
    ) {
    }
}
