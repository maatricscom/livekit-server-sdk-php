<?php

declare(strict_types=1);

namespace LiveKit\Tests\Support;

use LiveKit\AccessToken;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Proto\Room;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\ExpectationFailedException;
use Psr\Http\Message\RequestInterface;

final class TwirpTestCaseTest extends TwirpTestCase
{
    public function test_asserts_the_twirp_path_from_a_short_service_name(): void
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createRequest('POST', self::HOST . '/twirp/livekit.RoomService/CreateRoom')
            ->withHeader('Content-Type', 'application/protobuf');

        $this->assertTwirpRequest($request, 'RoomService', 'CreateRoom');
    }

    /**
     * The short-name convention exists so ServiceBase::rpc() callers never spell
     * out the `livekit.` prefix themselves. Passing an already-qualified name is
     * the exact mistake the convention guards against, and must fail rather than
     * silently matching a doubled prefix.
     */
    public function test_a_fully_qualified_service_name_does_not_match(): void
    {
        $psr17 = new Psr17Factory();
        $request = $psr17->createRequest('POST', self::HOST . '/twirp/livekit.RoomService/CreateRoom')
            ->withHeader('Content-Type', 'application/protobuf');

        $this->expectException(ExpectationFailedException::class);

        $this->assertTwirpRequest($request, 'livekit.RoomService', 'CreateRoom');
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

    /**
     * Task 13's createSipParticipant sends an empty VideoGrant alongside
     * SIPGrant(call: true): the `video` claim is present as {} rather than
     * omitted entirely. assertVideoGrant([]) must accept that present-and-empty
     * shape and reject a request that never set a video grant at all -- those
     * are different wire shapes, and collapsing them is the regression this
     * test guards against.
     */
    public function test_video_grant_assertion_distinguishes_present_empty_from_absent(): void
    {
        $this->assertVideoGrant([], $this->requestWithVideoGrant(new VideoGrant()));

        $this->expectException(ExpectationFailedException::class);

        $this->assertVideoGrant([], $this->requestWithoutGrants());
    }

    public function test_sip_grant_assertion_distinguishes_present_empty_from_absent(): void
    {
        $this->assertSipGrant([], $this->requestWithSipGrant(new SIPGrant()));

        $this->expectException(ExpectationFailedException::class);

        $this->assertSipGrant([], $this->requestWithoutGrants());
    }

    public function test_assert_no_video_grant_passes_when_absent_and_fails_when_present(): void
    {
        $this->assertNoVideoGrant($this->requestWithoutGrants());

        $this->expectException(ExpectationFailedException::class);

        $this->assertNoVideoGrant($this->requestWithVideoGrant(new VideoGrant()));
    }

    public function test_assert_no_sip_grant_passes_when_absent_and_fails_when_present(): void
    {
        $this->assertNoSipGrant($this->requestWithoutGrants());

        $this->expectException(ExpectationFailedException::class);

        $this->assertNoSipGrant($this->requestWithSipGrant(new SIPGrant()));
    }

    /**
     * array_filter()'s default callback drops every falsy value, not just the
     * null placeholder used for an omitted meta map. A '0' message is falsy in
     * PHP and must survive so a test asserting it gets a real diagnostic
     * instead of a silently malformed body.
     */
    public function test_error_response_preserves_a_falsy_message(): void
    {
        $response = $this->errorResponse(404, 'not_found', '0');

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['code' => 'not_found', 'msg' => '0'], $body);
    }

    private function requestWithoutGrants(): RequestInterface
    {
        return $this->requestWithToken((new AccessToken(self::API_KEY, self::API_SECRET))->toJwt());
    }

    private function requestWithVideoGrant(VideoGrant $grant): RequestInterface
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->addGrant($grant);

        return $this->requestWithToken($token->toJwt());
    }

    private function requestWithSipGrant(SIPGrant $grant): RequestInterface
    {
        $token = new AccessToken(self::API_KEY, self::API_SECRET);
        $token->addSipGrant($grant);

        return $this->requestWithToken($token->toJwt());
    }

    private function requestWithToken(string $jwt): RequestInterface
    {
        return $this->psr17()->createRequest('POST', self::HOST . '/twirp/livekit.RoomService/CreateRoom')
            ->withHeader('Content-Type', 'application/protobuf')
            ->withHeader('Authorization', 'Bearer ' . $jwt);
    }
}
