<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\AccessToken;
use LiveKit\Proto\RoomConfiguration;

/**
 * Participant-level options carried in an access token.
 *
 * Passed to AccessToken's constructor rather than to a service method, but it is
 * an option object like any other here: this namespace holds all of them, so
 * there is one place to look for anything configurable.
 */
final readonly class AccessTokenOptions
{
    /**
     * @param int|string        $ttl         Seconds as an int, or a duration string such as '6h', '10m', '45s', '2d'
     * @param array<string,string> $attributes
     * @param list<string>|null      $kindDetails
     * @param RoomConfiguration|null $roomConfig Applied when the token's holder creates the room.
     *                                           It travels inside the token, so a token holder can
     *                                           read it — see AccessToken::allowSensitiveCredentials().
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
        public ?RoomConfiguration $roomConfig = null,
    ) {
    }
}
