<?php

declare(strict_types=1);

namespace LiveKit\Tests\MockServer\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adds the test server's X-Lk-Mock control header to every request the SDK makes,
 * and records what went out and what came back.
 *
 * The header has to reach every attempt -- the API call itself, the
 * /settings/regions fetch, and each failover retry -- because every listener
 * reads its own copy to decide whether to fail. Decorating the PSR-18 client is
 * how we get that: the SDK performs all of its HTTP through the client it is
 * handed, so there is nothing to thread through ClientOptions.
 */
final class DirectiveHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @param array<string, mixed> $directives sent as X-Lk-Mock; omitted entirely when empty */
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly array $directives = [],
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if ($this->directives !== []) {
            $request = $request->withHeader('X-Lk-Mock', json_encode($this->directives, JSON_THROW_ON_ERROR));
        }

        $this->requests[] = $request;

        $response = $this->inner->sendRequest($request);

        $this->responses[] = $response;

        return $response;
    }

    /**
     * Every URI the SDK actually requested, in order. A failover run shows the
     * primary, then /settings/regions, then the fallback region.
     *
     * @return list<string>
     */
    public function uris(): array
    {
        return array_map(static fn (RequestInterface $r): string => (string) $r->getUri(), $this->requests);
    }

    /**
     * The host:port of each attempt, in order.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_map(static fn (RequestInterface $r): string => $r->getUri()->getAuthority(), $this->requests);
    }

    /**
     * The X-Lk-Mock-Region value of each response, in order. The mock sets it to
     * the index of the listener that served the request, and leaves it blank on a
     * region it made fail -- so this is how a test proves which region answered.
     *
     * @return list<string>
     */
    public function servingRegions(): array
    {
        return array_map(
            static fn (ResponseInterface $r): string => $r->getHeaderLine('X-Lk-Mock-Region'),
            $this->responses
        );
    }

    /** @return list<RequestInterface> */
    public function requests(): array
    {
        return $this->requests;
    }

    public function attempts(): int
    {
        return count($this->requests);
    }
}
