<?php

declare(strict_types=1);

namespace LiveKit\Tests\Integration;

use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\ListIngressOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Proto\IngressInfo;
use LiveKit\Proto\IngressInput;
use LiveKit\Tests\Integration\Support\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Ingress is safe to drive in full: RTMP and WHIP are both push inputs, so an
 * endpoint nobody streams to sits there costing nothing until it is deleted.
 *
 * The lifecycle runs once per input type, because the type is not a label on an
 * otherwise identical object -- the server hands back a different ingest URL for
 * each, on a different host -- and a create path that works for one is not
 * evidence about the other.
 *
 * URL_INPUT is deliberately never created. It is the one *pull* input: the server
 * fetches the media itself, which starts work and can cost money the moment the
 * ingress exists. What is asserted instead is that the server validates it, which
 * proves the route and the encoding without starting anything.
 */
final class IngressIntegrationTest extends IntegrationTestCase
{
    /** @return iterable<string, array{int}> */
    public static function pushInputs(): iterable
    {
        yield 'RTMP' => [IngressInput::RTMP_INPUT];
        yield 'WHIP' => [IngressInput::WHIP_INPUT];
    }

    #[DataProvider('pushInputs')]
    public function test_an_ingress_can_be_created_updated_listed_and_deleted(int $inputType): void
    {
        $name = $this->scratchName('ingress');
        $room = $this->scratchName('ingress-room');

        $ingress = $this->skipIfUnavailable(
            fn (): IngressInfo => $this->livekit->ingress->createIngress(new CreateIngressOptions(
                inputType: $inputType,
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

                $found = $this->livekit->ingress->listAllIngress(new ListIngressOptions(ingressId: $id));

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
            fn (): array => $this->livekit->ingress->listAllIngress(new ListIngressOptions(ingressId: $id)),
            sprintf('ingress %s', $id),
        );
    }

    public function test_the_room_name_filter_finds_every_ingress_bound_to_it(): void
    {
        $room = $this->scratchName('ingress-room');

        $first = $this->skipIfUnavailable(
            fn (): IngressInfo => $this->livekit->ingress->createIngress(new CreateIngressOptions(
                inputType: IngressInput::RTMP_INPUT,
                name: $this->scratchName('rtmp'),
                roomName: $room,
                participantIdentity: 'php-sdk-rtmp',
            )),
            'Ingress',
        );

        $second = $this->livekit->ingress->createIngress(new CreateIngressOptions(
            inputType: IngressInput::WHIP_INPUT,
            name: $this->scratchName('whip'),
            roomName: $room,
            participantIdentity: 'php-sdk-whip',
        ));

        $this->cleanUpAfter(
            body: function () use ($room, $first, $second): void {
                $found = $this->livekit->ingress->listAllIngress(new ListIngressOptions(roomName: $room));

                self::assertCount(2, $found, 'the roomName filter returns every ingress bound to the room');

                $ids = array_map(static fn (IngressInfo $i): string => $i->getIngressId(), $found);
                sort($ids);
                $expected = [$first->getIngressId(), $second->getIngressId()];
                sort($expected);

                self::assertSame($expected, $ids);

                // Unlike the ingressId filter, which raises not_found for an id that
                // is gone, a room with no ingress is an empty list rather than an
                // error. Measured against a live deployment; the two filters on one
                // rpc do not answer the same way.
                self::assertSame(
                    [],
                    $this->livekit->ingress->listAllIngress(new ListIngressOptions(roomName: $this->scratchName('empty'))),
                    'a room with no ingress is empty, not not_found'
                );
            },
            cleanup: function () use ($first, $second): void {
                $this->livekit->ingress->deleteIngress($first->getIngressId());
                $this->livekit->ingress->deleteIngress($second->getIngressId());
            },
            describe: sprintf('two ingresses on room "%s"', $room),
        );
    }

    /**
     * URL_INPUT pulls, so this asserts the server rejects an incomplete one rather
     * than creating it. Nothing is fetched and no ingress exists afterwards.
     */
    public function test_a_url_ingress_without_a_url_is_refused(): void
    {
        try {
            $this->skipIfUnavailable(
                fn (): IngressInfo => $this->livekit->ingress->createIngress(new CreateIngressOptions(
                    inputType: IngressInput::URL_INPUT,
                    name: $this->scratchName('url'),
                    roomName: $this->scratchName('url-room'),
                )),
                'Ingress',
            );
        } catch (TwirpException $e) {
            self::assertSame(TwirpErrorCode::INVALID_ARGUMENT, $e->getTwirpCode(), $e->getMessage());

            return;
        }

        self::fail('a URL ingress with no url should not have been created');
    }
}
