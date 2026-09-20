<?php

declare(strict_types=1);

namespace LiveKit\Tests\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Protobuf\Internal\Message;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Base case for service-client tests: a recording PSR-18 double plus assertions
 * for the Twirp envelope, the request body and the grants in the minted token.
 */
abstract class TwirpTestCase extends TestCase
{
    protected const HOST = 'https://test.livekit.cloud';

    protected MockHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new MockHttpClient();
    }

    protected function psr17(): Psr17Factory
    {
        return new Psr17Factory();
    }

    /** Wraps a protobuf message in a 200 binary-protobuf response. */
    protected function protoResponse(Message $message): ResponseInterface
    {
        $psr17 = $this->psr17();

        return $psr17->createResponse(200)
            ->withHeader('Content-Type', 'application/protobuf')
            ->withBody($psr17->createStream($message->serializeToString()));
    }

    /**
     * Queues a Twirp error response.
     *
     * @param array<string, string> $meta
     */
    protected function errorResponse(int $status, string $code, string $message, array $meta = []): ResponseInterface
    {
        $psr17 = $this->psr17();
        $body = json_encode(
            array_filter(['code' => $code, 'msg' => $message, 'meta' => $meta === [] ? null : $meta]),
            JSON_THROW_ON_ERROR
        );

        return $psr17->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream($body));
    }

    /**
     * Asserts the Twirp envelope: HTTP method, URL path and content type.
     *
     * @param string $service The SHORT service name, e.g. 'RoomService'. This method
     *                        prepends `livekit.` — do not pass it yourself.
     */
    protected function assertTwirpRequest(RequestInterface $request, string $service, string $method): void
    {
        self::assertSame('POST', $request->getMethod());
        self::assertSame(
            sprintf('/twirp/livekit.%s/%s', $service, $method),
            $request->getUri()->getPath()
        );
        self::assertSame('application/protobuf', $request->getHeaderLine('Content-Type'));
    }

    /**
     * Verifies the signature of the minted JWT and returns its claims.
     *
     * @return array<string, mixed>
     */
    protected function claims(RequestInterface $request): array
    {
        $header = $request->getHeaderLine('Authorization');
        self::assertStringStartsWith('Bearer ', $header);

        $decoded = JWT::decode(substr($header, 7), new Key(self::API_SECRET, 'HS256'));

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        return $claims;
    }

    /**
     * Asserts the `video` claim contains exactly these keys and values — no more.
     * The exact-count check is what catches an over-broad grant.
     *
     * @param array<string, bool|string> $expected
     */
    protected function assertVideoGrant(array $expected, RequestInterface $request): void
    {
        $video = $this->claims($request)['video'] ?? [];

        if ($expected === []) {
            self::assertSame([], $video, 'expected no video grant');

            return;
        }

        self::assertIsArray($video);

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $video, sprintf('video grant is missing "%s"', $key));
            self::assertSame($value, $video[$key]);
        }

        self::assertCount(count($expected), $video, 'video grant carries unexpected extra claims');
    }

    /**
     * Asserts the `sip` claim contains exactly these keys and values.
     *
     * @param array<string, bool> $expected
     */
    protected function assertSipGrant(array $expected, RequestInterface $request): void
    {
        $sip = $this->claims($request)['sip'] ?? [];

        if ($expected === []) {
            self::assertSame([], $sip, 'expected no sip grant');

            return;
        }

        self::assertIsArray($sip);

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $sip, sprintf('sip grant is missing "%s"', $key));
            self::assertSame($value, $sip[$key]);
        }

        self::assertCount(count($expected), $sip, 'sip grant carries unexpected extra claims');
    }

    /**
     * Decodes the captured request body into the given proto request class.
     *
     * @template T of Message
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function decodeRequest(string $class): Message
    {
        $message = new $class();
        $message->mergeFromString($this->http->lastBody());

        return $message;
    }
}
