<?php

declare(strict_types=1);

namespace LiveKit;

/**
 * Participant-level options carried in an access token.
 *
 * Sits beside AccessToken because the two are always used together. This is not a
 * per-call option object; those live in the LiveKit\Options namespace.
 */
final readonly class AccessTokenOptions
{
    /**
     * @param int|string        $ttl         Seconds as an int, or a duration string such as '6h', '10m', '45s', '2d'
     * @param array<string,string> $attributes
     * @param list<string>|null $kindDetails
     */
    public function __construct(
        public ?string $identity = null,
        public ?string $name = null,
        public int|string $ttl = AccessToken::DEFAULT_TTL_SECONDS,
        public ?string $metadata = null,
        public array $attributes = [],
        public ?string $kind = null,
        public ?array $kindDetails = null,
        public ?string $roomPreset = null,
    ) {
    }
}
