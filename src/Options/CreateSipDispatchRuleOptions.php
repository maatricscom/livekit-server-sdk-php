<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\RoomConfiguration;

/**
 * Options for SipClient::createSipDispatchRule().
 *
 * Maps onto the flat fields of livekit.CreateSIPDispatchRuleRequest.
 */
final readonly class CreateSipDispatchRuleOptions
{
    /**
     * @param list<string>|null          $trunkIds       trunks accepted by this rule; empty matches all trunks
     * @param list<string>|null          $inboundNumbers the rule only accepts calls made to these numbers
     * @param array<string, string>|null $attributes     inherited by participants created by this rule
     * @param string|null                $roomPreset     LiveKit Cloud only
     */
    public function __construct(
        public ?string $name = null,
        public ?string $metadata = null,
        public ?array $trunkIds = null,
        public ?bool $hidePhoneNumber = null,
        public ?array $inboundNumbers = null,
        public ?array $attributes = null,
        public ?string $roomPreset = null,
        public ?RoomConfiguration $roomConfig = null,
    ) {
    }
}
