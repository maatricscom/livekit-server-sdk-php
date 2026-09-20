<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Proto\AgentDispatch;
use LiveKit\Proto\CreateAgentDispatchRequest;
use LiveKit\Proto\DeleteAgentDispatchRequest;

final class AgentDispatchClient extends ServiceBase
{
    private const SERVICE = 'AgentDispatchService';

    public function createDispatch(
        string $room,
        string $agentName,
        ?CreateDispatchOptions $options = null,
    ): AgentDispatch {
        $request = new CreateAgentDispatchRequest();
        $request->setRoom($room);
        $request->setAgentName($agentName);

        if ($options !== null) {
            if ($options->metadata !== null) {
                $request->setMetadata($options->metadata);
            }

            if ($options->deployment !== null) {
                $request->setDeployment($options->deployment);
            }

            if ($options->attributes !== []) {
                $request->setAttributes($options->attributes);
            }

            if ($options->restartPolicy !== null) {
                $request->setRestartPolicy($options->restartPolicy);
            }
        }

        return $this->rpc(
            self::SERVICE,
            'CreateDispatch',
            $request,
            AgentDispatch::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
    }

    public function deleteDispatch(string $dispatchId, string $room): AgentDispatch
    {
        $request = new DeleteAgentDispatchRequest();
        $request->setDispatchId($dispatchId);
        $request->setRoom($room);

        return $this->rpc(
            self::SERVICE,
            'DeleteDispatch',
            $request,
            AgentDispatch::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );
    }
}
