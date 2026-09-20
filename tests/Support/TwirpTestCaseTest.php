<?php

declare(strict_types=1);

namespace LiveKit\Tests\Support;

use LiveKit\Proto\Room;
use Nyholm\Psr7\Factory\Psr17Factory;

final class TwirpTestCaseTest extends TwirpTestCase
{
    public function test_asserts_the_twirp_path_from_a_short_service_name(): void
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createRequest('POST', self::HOST . '/twirp/livekit.RoomService/CreateRoom')
            ->withHeader('Content-Type', 'application/protobuf');

        $this->assertTwirpRequest($request, 'RoomService', 'CreateRoom');
    }

    public function test_builds_a_protobuf_response(): void
    {
        $room = new Room();
        $room->setName('my-room');

        $response = $this->protoResponse($room);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/protobuf', $response->getHeaderLine('Content-Type'));

        $decoded = new Room();
        $decoded->mergeFromString((string) $response->getBody());

        self::assertSame('my-room', $decoded->getName());
    }
}
