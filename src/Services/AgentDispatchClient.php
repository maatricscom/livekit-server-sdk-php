<?php

declare(strict_types=1);

namespace LiveKit\Services;

use LiveKit\Contracts\AgentDispatchClientInterface;
use LiveKit\Enums\ProtoEnum;
use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Grants\VideoGrant;
use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Proto\AgentDispatch;
use LiveKit\Proto\CreateAgentDispatchRequest;
use LiveKit\Proto\DeleteAgentDispatchRequest;
use LiveKit\Proto\JobRestartPolicy;
use LiveKit\Proto\ListAgentDispatchRequest;
use LiveKit\Proto\ListAgentDispatchResponse;

final class AgentDispatchClient extends ServiceBase implements AgentDispatchClientInterface
{
    private const string SERVICE = 'AgentDispatchService';

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
                $request->setRestartPolicy(ProtoEnum::check(JobRestartPolicy::class, $options->restartPolicy, 'restartPolicy'));
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
     * Fetches one dispatch, or null when the room has no dispatch with that id.
     *
     * livekit.AgentDispatchService has no GetDispatch rpc; ListDispatch filtered by
     * dispatch_id is how the one-dispatch case is served, and this is the shape you
     * usually want it in. Delegates rather than issuing the rpc itself, so the two
     * cannot drift apart on the grant or on how the response is unwrapped.
     *
     * A real server answers a filter that matches nothing with not_found rather than
     * with an empty list, so the catch — not the `?? null` — is what makes the null
     * in this signature reachable. Only that one code is swallowed: an unknown room
     * is still "no such dispatch", but a bad grant or an unreachable host is not, and
     * those keep throwing. Node's getDispatch() returns undefined on the empty list
     * alone and so throws in the case it documents; we diverge deliberately.
     */
    public function getDispatch(string $dispatchId, string $room): ?AgentDispatch
    {
        try {
            return $this->listDispatch($room, $dispatchId)[0] ?? null;
        } catch (TwirpException $e) {
            if ($e->getTwirpCode() === TwirpErrorCode::NOT_FOUND) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Lists the dispatches of a room. When $dispatchId is given, the server
     * returns only that dispatch — though getDispatch() is the friendlier way to
     * ask for exactly one.
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
            $dispatches[] = $dispatch;
        }

        return $dispatches;
    }
}
