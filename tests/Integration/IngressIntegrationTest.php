<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\ListIngressOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Proto\IngressInfo;
use LiveKit\Proto\IngressInput;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;

/**
 * Ingress is the one service besides rooms whose full lifecycle is safe to drive
 * here: an RTMP endpoint that nobody streams to costs nothing, and it can be
 * deleted again. That makes it the only place this suite proves a create,
 * an update and a delete all reach a real server and come back decodable.
 */
final class IngressIntegrationTest extends IntegrationTestCase
{
    public function test_an_ingress_can_be_created_updated_listed_and_deleted(): void
    {
        $name = $this->scratchName('ingress');
        $room = $this->scratchName('ingress-room');

        $ingress = $this->skipIfUnavailable(
            fn (): IngressInfo => $this->livekit->ingress->createIngress(new CreateIngressOptions(
                inputType: IngressInput::RTMP_INPUT,
                name: $name,
                roomName: $room,
                participantIdentity: 'php-sdk-integration',
            )),
            'Ingress',
        );

        $id = $ingress->getIngressId();

        $this->cleanUpAfter(
            body: function () use ($id, $name, $room): void {
                self::assertNotSame('', $id, 'the server assigns an ingress id');

                $found = $this->livekit->ingress->listIngress(new ListIngressOptions(ingressId: $id));

                self::assertCount(1, $found, 'the ingressId filter reaches the server');
                self::assertSame($name, $found[0]->getName());
                self::assertSame($room, $found[0]->getRoomName());

                // Only that it is populated. The scheme differs between a Cloud
                // project and a self-hosted server, and this suite should not
                // fail over which one the operator happens to be running.
                self::assertNotSame('', $found[0]->getUrl(), 'the server hands back an ingest url');

                // Partial update: only the name is sent, and everything else must
                // survive. This is where a client that sends unset fields as empty
                // strings would silently clear the room binding.
                $renamed = $name . '-renamed';
                $updated = $this->livekit->ingress->updateIngress($id, new UpdateIngressOptions(name: $renamed));

                self::assertSame($renamed, $updated->getName());
                self::assertSame($room, $updated->getRoomName(), 'the unchanged field was not cleared');
            },
            cleanup: fn () => $this->livekit->ingress->deleteIngress($id),
            describe: sprintf('ingress "%s" (%s)', $name, $id),
        );

        $this->assertGoneAfterDelete(
            fn (): array => $this->livekit->ingress->listIngress(new ListIngressOptions(ingressId: $id)),
            sprintf('ingress %s', $id),
        );
    }
}
