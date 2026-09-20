<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\Pagination;

/**
 * Filters for SipClient::listSipInboundTrunk() and SipClient::listSipOutboundTrunk().
 *
 * Maps onto livekit.ListSIPInboundTrunkRequest / livekit.ListSIPOutboundTrunkRequest,
 * which declare the same three fields. With no filters set, all trunks are listed.
 */
final readonly class ListSipTrunkOptions
{
    /**
     * @param list<string>|null $trunkIds trunk IDs to list; the response keeps this order
     * @param list<string>|null $numbers  only list trunks containing one of these numbers
     */
    public function __construct(
        public ?Pagination $page = null,
        public ?array $trunkIds = null,
        public ?array $numbers = null,
    ) {
    }
}
