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
}
