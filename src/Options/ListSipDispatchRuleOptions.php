<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\Pagination;

/**
 * Filters for SipClient::listSipDispatchRule().
 *
 * Maps onto livekit.ListSIPDispatchRuleRequest. With no filters set, all rules are listed.
 */
final readonly class ListSipDispatchRuleOptions
{
    /**
     * @param list<string>|null $dispatchRuleIds rule IDs to list; the response keeps this order
     * @param list<string>|null $trunkIds        only list rules containing one of these trunk IDs
     */
    public function __construct(
        public ?Pagination $page = null,
        public ?array $dispatchRuleIds = null,
        public ?array $trunkIds = null,
    ) {
    }
}
