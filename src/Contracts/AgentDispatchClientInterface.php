<?php

declare(strict_types=1);

namespace LiveKit\Contracts;

use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Proto\AgentDispatch;

interface AgentDispatchClientInterface
{
    public function createDispatch(
        string $room,
        string $agentName,
        ?CreateDispatchOptions $options = null,
    ): AgentDispatch;

    public function deleteDispatch(string $dispatchId, string $room): AgentDispatch;

    /**
     * Lists the dispatches of a room. When $dispatchId is given, the server
     * returns only that dispatch — this is the second shape the Node SDK
     * exposes, and livekit.ListAgentDispatchRequest.dispatch_id backs it.
     *
     * @return list<AgentDispatch>
     */
    public function listDispatch(string $room, ?string $dispatchId = null): array;
}
