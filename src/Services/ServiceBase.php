<?php

declare(strict_types=1);

namespace LiveKit\Services;

use Google\Protobuf\Internal\Message;
use LiveKit\AccessToken;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Grants\SIPGrant;
use LiveKit\Grants\VideoGrant;
use LiveKit\Http\TwirpClient;
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Options\ClientOptions;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Shared behaviour for every LiveKit service client: credential handling,
 * per-call token minting, and access to the Twirp transport.
 *
 * Each concrete client declares the grant its methods need, mirroring how the
 * Node SDK's ServiceBase.authHeader() is called per method rather than once
 * per client.
 */
abstract class ServiceBase
{
    /** Per-API-call tokens are short-lived; this matches the Node SDK. */
    public const SERVICE_TOKEN_TTL_SECONDS = 600;

    private readonly ?string $apiKey;

    private readonly ?string $apiSecret;

    protected readonly ClientOptions $options;

    protected readonly TwirpClient $transport;

    public function __construct(
        ?string $host = null,
        ?string $apiKey = null,
        ?string $apiSecret = null,
        ?ClientOptions $options = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
    ) {
        $host ??= self::env('LIVEKIT_URL');

        if ($host === null || $host === '') {
            throw ConfigurationException::missingHost();
        }

        $this->options = $options ?? new ClientOptions();

        $this->apiKey = $apiKey ?? self::env('LIVEKIT_API_KEY');
        $this->apiSecret = $apiSecret ?? self::env('LIVEKIT_API_SECRET');

        // Credentials are optional only when a pre-signed token is supplied.
        if ($this->options->token === null
            && ($this->apiKey === null || $this->apiKey === '' || $this->apiSecret === null || $this->apiSecret === '')
        ) {
            throw ConfigurationException::missingCredentials();
        }

        $this->transport = new TwirpClient(
            $host,
            $this->options,
            $httpClient,
            $requestFactory,
            $streamFactory,
        );
    }

    /**
     * Mints a fresh short-lived token carrying exactly the grants this call needs,
     * or returns the caller's pre-signed token.
     *
     * Failures propagate. Swallowing them would turn a signing problem into an
     * opaque 401 from the server.
     */
    protected function authHeader(VideoGrant $video, ?SIPGrant $sip = null): string
    {
        if ($this->options->token !== null) {
            return $this->options->token;
        }

        $token = new AccessToken(
            $this->apiKey,
            $this->apiSecret,
            new AccessTokenOptions(ttl: self::SERVICE_TOKEN_TTL_SECONDS),
        );

        $token->addGrant($video);

        if ($sip !== null) {
            $token->addSipGrant($sip);
        }

        return $token->toJwt();
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $responseClass
     *
     * @return T
     */
    protected function rpc(
        string $service,
        string $method,
        Message $request,
        string $responseClass,
        string $jwt,
        ?int $timeoutSeconds = null,
    ): Message {
        return $this->transport->request(
            $service,
            $method,
            $request,
            $responseClass,
            $jwt,
            $timeoutSeconds,
        );
    }

    /**
     * getenv() returns false when unset, so `??` never fires on its result.
     */
    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
