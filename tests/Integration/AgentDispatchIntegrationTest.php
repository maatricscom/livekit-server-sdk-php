<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Options\CreateDispatchOptions;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Proto\AgentDispatch;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;

/**
 * Dispatching an agent that does not exist is harmless: the request is recorded
 * and nothing is ever assigned, because no worker registered under that name.
 * That makes the create/list/delete path safe to drive against a real project,
 * which is the point -- it is the only agent RPC a server SDK can prove without
 * a running agent.
 */
final class AgentDispatchIntegrationTest extends IntegrationTestCase
{
    public function test_a_dispatch_can_be_created_found_and_deleted(): void
    {
        $room = $this->scratchName('dispatch-room');
        $agent = $this->scratchName('agent');

        $this->cleanUpAfter(
            body: function () use ($room, $agent): void {
                $this->livekit->room->createRoom(new CreateRoomOptions(name: $room, emptyTimeout: 30));

                $dispatch = $this->skipIfUnavailable(
                    fn (): AgentDispatch => $this->livekit->agentDispatch->createDispatch(
                        $room,
                        $agent,
                        new CreateDispatchOptions(metadata: '{"from":"php-sdk-integration"}'),
                    ),
                    'Agent dispatch',
                );

                $id = $dispatch->getId();
                self::assertNotSame('', $id, 'the server assigns a dispatch id');
                self::assertSame($agent, $dispatch->getAgentName());

                $listed = $this->livekit->agentDispatch->listDispatch($room);
                $ids = array_map(static fn (AgentDispatch $d): string => $d->getId(), $listed);
                self::assertContains($id, $ids);

                // getDispatch() is this SDK's own convenience over ListDispatch --
                // the service has no GetDispatch rpc — so it is worth proving
                // against a real server rather than only against the mock.
                $one = $this->livekit->agentDispatch->getDispatch(dispatchId: $id, room: $room);
                self::assertNotNull($one);
                self::assertSame($id, $one->getId());

                $this->livekit->agentDispatch->deleteDispatch(dispatchId: $id, room: $room);

                self::assertNull(
                    $this->livekit->agentDispatch->getDispatch(dispatchId: $id, room: $room),
                    'deleteDispatch actually removed it'
                );
            },
            cleanup: fn () => $this->livekit->room->deleteRoom($room),
            describe: sprintf('room "%s"', $room),
        );
    }
}
