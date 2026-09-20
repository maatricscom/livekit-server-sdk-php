<?php

declare(strict_types=1);

namespace LiveKit\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A recording PSR-18 double. Queue responses with pushResponse(), then inspect
 * what the SDK sent with lastRequest() / requests().
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses = [];

    /** @var list<\Throwable> */
    private array $exceptions = [];

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        if ($this->exceptions !== []) {
            throw array_shift($this->exceptions);
        }

        if ($this->responses === []) {
            throw new \LogicException(sprintf(
                'MockHttpClient received a request to %s but no response was queued.',
                (string) $request->getUri()
            ));
        }

        return array_shift($this->responses);
    }

    public function pushResponse(ResponseInterface $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function pushException(\Throwable $exception): self
    {
        $this->exceptions[] = $exception;

        return $this;
    }

    public function lastRequest(): RequestInterface
    {
        if ($this->requests === []) {
            throw new \LogicException('No request was recorded.');
        }

        return $this->requests[array_key_last($this->requests)];
    }

    /** The body bytes of the most recent request. */
    public function lastBody(): string
    {
        return (string) $this->lastRequest()->getBody();
    }

    /**
     * @return list<RequestInterface>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function requestCount(): int
    {
        return count($this->requests);
    }
}
