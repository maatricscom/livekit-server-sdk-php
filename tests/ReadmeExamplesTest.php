<?php

declare(strict_types=1);

namespace LiveKit\Tests;

use LiveKit\Contracts\AgentDispatchClientInterface;
use LiveKit\Contracts\ConnectorClientInterface;
use LiveKit\Contracts\EgressClientInterface;
use LiveKit\Contracts\IngressClientInterface;
use LiveKit\Contracts\RoomServiceClientInterface;
use LiveKit\Contracts\SipClientInterface;
use LiveKit\LiveKitAPI;
use LiveKit\Options\CreateRoomOptions;
use LiveKit\Proto\Room;
use LiveKit\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The class from README.md's "Dependency injection and testing" section, kept
 * compilable so the example cannot rot into something that does not run.
 */
final readonly class RoomProvisioner
{
    public function __construct(private RoomServiceClientInterface $rooms)
    {
    }

    public function provision(string $name): string
    {
        return $this->rooms->createRoom(new CreateRoomOptions(name: $name))->getSid();
    }
}

/**
 * Runs what README.md tells people to write.
 *
 * Documentation is the one part of a package that nothing else checks: a snippet
 * can go on describing an API that has since been renamed, and every test stays
 * green. These two are cheap to keep honest, and they cover the claims most
 * likely to be acted on — the example someone pastes, and the table of which
 * interface each client implements.
 */
final class ReadmeExamplesTest extends TestCase
{
    public function test_the_dependency_injection_example_runs(): void
    {
        // createStub, not createMock: nothing here verifies an interaction, and
        // PHPUnit 12 emits a notice for a mock with no configured expectations.
        // The README says createStub for the same reason.
        $rooms = $this->createStub(RoomServiceClientInterface::class);
        $rooms->method('createRoom')->willReturn((new Room())->setSid('RM_test'));

        self::assertSame('RM_test', (new RoomProvisioner($rooms))->provision('my-room'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function documentedInterfaces(): iterable
    {
        yield 'room' => ['room', RoomServiceClientInterface::class];
        yield 'egress' => ['egress', EgressClientInterface::class];
        yield 'ingress' => ['ingress', IngressClientInterface::class];
        yield 'sip' => ['sip', SipClientInterface::class];
        yield 'agentDispatch' => ['agentDispatch', AgentDispatchClientInterface::class];
        yield 'connector' => ['connector', ConnectorClientInterface::class];
    }

    /** @param class-string $interface */
    #[DataProvider('documentedInterfaces')]
    public function test_every_documented_interface_is_satisfied_by_its_client(string $property, string $interface): void
    {
        // The README's table promises these bindings; container wiring written
        // against it fails at runtime, not at analysis time, if one is wrong.
        $this->withEnv([
            'LIVEKIT_URL' => 'https://example.livekit.cloud',
            'LIVEKIT_API_KEY' => self::API_KEY,
            'LIVEKIT_API_SECRET' => self::API_SECRET,
            'LIVEKIT_TOKEN' => null,
        ], function () use ($property, $interface): void {
            self::assertInstanceOf($interface, (new LiveKitAPI())->{$property});
        });
    }
}
