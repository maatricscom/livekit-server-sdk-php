<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Contracts\AgentDispatchClientInterface;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Proto\AgentDispatch;
use LiveKit\Proto\CreateAgentDispatchRequest;
use LiveKit\Proto\DeleteAgentDispatchRequest;
use LiveKit\Proto\ListAgentDispatchRequest;
use LiveKit\Proto\ListAgentDispatchResponse;

final class AgentDispatchClient extends ServiceBase implements AgentDispatchClientInterface
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

    /**
     * Lists the dispatches of a room. When $dispatchId is given, the server
     * returns only that dispatch.
     *
     * @return list<AgentDispatch>
     */
    public function listDispatch(string $room, ?string $dispatchId = null): array
    {
        $request = new ListAgentDispatchRequest();
        $request->setRoom($room);

        if ($dispatchId !== null) {
            $request->setDispatchId($dispatchId);
        }

        $response = $this->rpc(
            self::SERVICE,
            'ListDispatch',
            $request,
            ListAgentDispatchResponse::class,
            $this->authHeader(new VideoGrant(roomAdmin: true, room: $room)),
        );

        /** @var list<AgentDispatch> $dispatches */
        $dispatches = [];

        foreach ($response->getAgentDispatches() as $dispatch) {
            // RepeatedField's iterator carries no generic value type, so this
            // yields mixed — unlike the rpc() return, which the analyser infers.
            assert($dispatch instanceof AgentDispatch);
            $dispatches[] = $dispatch;
        }

        return $dispatches;
    }
}
