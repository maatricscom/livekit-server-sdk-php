<?php

declare(strict_types=1);

namespace LiveKit\Options;

use LiveKit\Proto\RoomAgentDispatch;

/**
 * Options for ConnectorClient::connectTwilioCall().
 *
 * Maps onto livekit.ConnectTwilioCallRequest.
 */
final readonly class ConnectTwilioCallOptions
{
    /**
     * @param list<RoomAgentDispatch>    $agents                agents to dispatch into the room
     * @param array<string, string>|null $participantAttributes attached to the created participant
     * @param string|null                $destinationCountry    ISO 3166-1 alpha-2 country the call terminates in
     */
    public function __construct(
        public array $agents = [],
        public ?string $participantIdentity = null,
        public ?string $participantName = null,
        public ?string $participantMetadata = null,
        public ?array $participantAttributes = null,
        public ?string $destinationCountry = null,
    ) {
    }
}
