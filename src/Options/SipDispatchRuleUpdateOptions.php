<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\ListUpdate;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPMediaConfig;

/**
 * Fields for SipClient::updateSipDispatchRuleFields().
 *
 * Maps onto livekit.SIPDispatchRuleUpdate, the 'update' arm of the oneof in
 * livekit.UpdateSIPDispatchRuleRequest. A null property leaves the field untouched.
 */
final readonly class SipDispatchRuleUpdateOptions
{
    /**
     * @param array<string, string>|null $attributes      replaces the rule's attribute map
     * @param int|null                   $mediaEncryption a LiveKit\Proto\SIPMediaEncryption constant; deprecated upstream in favour of $media->getEncryption()
     */
    public function __construct(
        public ?ListUpdate $trunkIds = null,
        public ?SIPDispatchRule $rule = null,
        public ?string $name = null,
        public ?string $metadata = null,
        public ?array $attributes = null,
        public ?int $mediaEncryption = null,
        public ?SIPMediaConfig $media = null,
    ) {
    }
}
