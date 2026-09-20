<?php

declare(strict_types=1);

namespace LiveKit\Http;

use Google\Protobuf\Internal\Message;
use LiveKit\ClientOptions;
use LiveKit\Enums\WireFormat;
use LiveKit\Exceptions\ConfigurationException;
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpException;
use LiveKit\Proto\ProtocolVersion;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The only component in this SDK that performs HTTP.
 *
 * Twirp is a thin protocol: POST {host}{prefix}/livekit.{Service}/{Method} with
 * the serialized request as the body. Errors always come back as a JSON
 * {code, msg, meta} envelope, even when the request was binary protobuf.
 *
 * A request may be attempted against more than one host: see Failover for when
 * that happens and why it is restricted to LiveKit Cloud domains.
 */
final class TwirpClient
{
    public const VERSION = '0.1.0';

    public const REQUEST_ID_HEADER = 'X-Livekit-Request-Id';

    public const USER_AGENT_PREFIX = 'livekit-server-sdk-php/';

    /** Origin-rooted, never relative to the configured host's path. */
    private const REGIONS_PATH = '/settings/regions';

    /**
     * Identifies both the SDK and the protocol revision its generated classes came
     * from. The protocol part is what tells you whether a missing field is a bug or
     * simply newer than the tree this package was built against — it shows up in
     * LiveKit's server logs and in any bug report that pastes the request.
     */
    public static function userAgent(): string
    {
        return sprintf('%s%s (protocol %s)', self::USER_AGENT_PREFIX, self::VERSION, ProtocolVersion::TAG);
    }

    private readonly string $host;

    private readonly ClientInterface $httpClient;

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    private readonly RegionCache $regionCache;

    public function __construct(
        string $host,
        private readonly ClientOptions $options = new ClientOptions(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?RegionCache $regionCache = null,
    ) {
        $this->host = self::normalizeHost($host);
        $this->httpClient = HttpClientResolver::client($httpClient);
        $this->requestFactory = HttpClientResolver::requestFactory($requestFactory);
        $this->streamFactory = HttpClientResolver::streamFactory($streamFactory);
        // Shared by default so the five clients behind one LiveKitAPI discover the
        // project's regions once between them rather than once each.
        $this->regionCache = $regionCache ?? RegionCache::shared();
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $responseClass
     * @param int|null        $timeoutSeconds Overrides ClientOptions::$requestTimeout for this call
     *
     * @return T
     */
    public function request(
        string $service,
        string $method,
        Message $request,
        string $responseClass,
        string $jwt,
        ?int $timeoutSeconds = null,
    ): Message {
        $path = sprintf('%s/livekit.%s/%s', $this->options->prefix, $service, $method);

        $body = $this->options->wireFormat === WireFormat::Json
            ? $request->serializeToJsonString()
            : $request->serializeToString();

        $timeout = $timeoutSeconds ?? $this->options->requestTimeout;

        // One id for the whole call, reused by every attempt. A retry is the same
        // request sent again, so regenerating the id here would present it to the
        // server as a new one and defeat server-side deduplication.
        $requestId = self::requestId();
        $jwtHeader = 'Bearer ' . $jwt;

        $maxAttempts = Failover::attempts(
            $this->options->failover,
            self::hostnameOf($this->host),
            $this->options->failoverForce,
            $timeout,
        );

        $current = $this->host;
        $attempted = [Failover::hostKey($this->host) => true];
        /** @var list<string>|null $regions */
        $regions = null;

        $attempt = 0;
        $pinRedirects = 0;

        // Asked once: may this call's token travel to a host we learn at runtime?
        // Both the failover retry and the region-pin redirect need the answer, and
        // the pin redirect needs it even when failover is switched off.
        $mayRedirect = Failover::allowsRedirect(self::hostnameOf($this->host), $this->options->failoverForce);

        while (true) {
            $uri = $current . $path;

            // Rebuilt per attempt rather than reused: a body stream that has been
            // read once is not guaranteed to rewind, and PSR-18 says nothing about
            // whether a client leaves it rewound.
            $httpRequest = $this->buildRequest($uri, $body, $jwtHeader, $requestId, $timeout);

            $response = null;
            $transportError = null;

            try {
                $response = $this->httpClient->sendRequest($httpRequest);
            } catch (ClientExceptionInterface $e) {
                $transportError = $e;
            }

            $error = null;

            if ($response !== null) {
                $status = $response->getStatusCode();

                if ($status >= 200 && $status < 300) {
                    return $this->decode($response, $responseClass, $uri);
                }

                // A region pin is a redirect, not a failure: the project is pinned
                // to regions this one is not among, and the request was turned away
                // before it was served. Handled before the error is built, because
                // the body is the middleware's plain text rather than a Twirp
                // envelope -- there is no error here to report, only somewhere else
                // to go.
                if ($status === Failover::REGION_PIN_STATUS
                    && $mayRedirect
                    && $pinRedirects < Failover::MAX_PIN_REDIRECTS
                ) {
                    // The 451 names no destination. A pinned project's
                    // /settings/regions lists only the regions it is allowed, so
                    // rediscovering is what turns the rejection into a destination
                    // -- and anything cached from before the pin took effect is
                    // wrong by definition, hence the forced refresh.
                    $regions = $this->discoverRegions($jwtHeader, $requestId, refresh: true);
                    $next = Failover::pickNext($regions, $attempted);

                    if ($next !== null) {
                        ++$pinRedirects;
                        $attempted[Failover::hostKey($next)] = true;
                        $current = $next;

                        // No backoff. Nothing failed and nothing is overloaded; the
                        // server answered immediately and deterministically.
                        continue;
                    }
                }

                $error = self::errorFromResponse($status, (string) $response->getBody());
            }

            // A transport error or a 5xx may be this region's problem; a 4xx is the
            // request's, and replaying it elsewhere would only repeat the answer.
            //
            // A SipCallError is the exception, and a deliberate divergence from the
            // Node SDK, which retries it. SIP status metadata means the call reached
            // the far end and the far end answered -- busy, declined, no answer. That
            // is not a region being unhealthy, so another region cannot give a better
            // answer; it would just dial the number a second time, ring a real phone
            // again, and bill for it. LiveKit returns these as HTTP 500, so nothing
            // but the metadata distinguishes them from a genuine server fault.
            $retryable = $transportError !== null
                || ($error !== null && $error->getHttpStatus() >= 500 && !$error instanceof SipCallError);

            $next = null;

            if ($retryable && $attempt + 1 < $maxAttempts) {
                // ??= on purpose: under a pin, $regions already holds the allowed
                // list, and those are the only regions that will answer at all.
                $regions ??= $this->discoverRegions($jwtHeader, $requestId);
                $next = Failover::pickNext($regions, $attempted);
            }

            if ($next === null) {
                if ($error !== null) {
                    throw $error;
                }

                /** @var ClientExceptionInterface $transportError */
                throw new TwirpException(
                    sprintf('The request to %s failed: %s', $uri, $transportError->getMessage()),
                    'unavailable',
                    0,
                    [],
                    $transportError,
                );
            }

            usleep(Failover::backoffMicroseconds($attempt, $this->options->failoverBackoffMs));

            ++$attempt;
            $attempted[Failover::hostKey($next)] = true;
            $current = $next;
        }
    }

    /**
     * Builds one attempt. Only the URI differs between attempts; everything else,
     * the request id included, is identical.
     */
    private function buildRequest(
        string $uri,
        string $body,
        string $jwtHeader,
        string $requestId,
        int $timeoutSeconds,
    ): RequestInterface {
        return $this->requestFactory->createRequest('POST', $uri)
            ->withHeader('Content-Type', $this->options->wireFormat->contentType())
            ->withHeader('Accept', $this->options->wireFormat->contentType())
            ->withHeader('Authorization', $jwtHeader)
            ->withHeader('User-Agent', self::userAgent())
            ->withHeader(self::REQUEST_ID_HEADER, $requestId)
            ->withHeader('X-Twirp-Timeout-Ms', (string) ($timeoutSeconds * 1000))
            ->withBody($this->streamFactory->createStream($body));
    }

    /**
     * @template T of Message
     *
     * @param class-string<T> $responseClass
     *
     * @return T
     */
    private function decode(ResponseInterface $response, string $responseClass, string $uri): Message
    {
        $responseBody = (string) $response->getBody();
        $message = new $responseClass();

        if ($this->options->wireFormat === WireFormat::Json) {
            // A 204, or a proxy that strips the body, leaves nothing to decode.
            // Binary mode yields an all-defaults message for empty input, so match
            // that rather than throwing for a response the server considered a success.
            if (trim($responseBody) !== '') {
                try {
                    // The second argument is ignore_unknown. Without it, any field
                    // LiveKit adds to a response throws and breaks the SDK.
                    $message->mergeFromJsonString($responseBody, true);
                } catch (\Throwable $e) {
                    throw new TwirpException(
                        sprintf('Could not decode the JSON response from %s: %s', $uri, $e->getMessage()),
                        'internal',
                        $response->getStatusCode(),
                        [],
                        $e,
                    );
                }
            }
        } else {
            $message->mergeFromString($responseBody);
        }

        return $message;
    }

    /**
     * Asks the host which regions it can offer, for a retry.
     *
     * Best-effort by design: this runs only when a request has already failed, so
     * a discovery that fails too must not replace the original error with its own.
     * Anything short of a usable list returns empty, which makes the caller throw
     * the failure the user actually cares about.
     *
     * The response is cached per host for the lifetime Cache-Control allows;
     * $refresh discards that entry first, for a caller that knows it is stale.
     *
     * @return list<string>
     */
    private function discoverRegions(string $jwtHeader, string $requestId, bool $refresh = false): array
    {
        $origin = Failover::origin($this->host);

        if ($origin === null) {
            return [];
        }

        $hostKey = Failover::hostKey($origin);

        if ($refresh) {
            // Dropped rather than merely bypassed, so a later call in this process
            // does not go on using a list the server has already contradicted.
            $this->regionCache->forget($hostKey);
        } else {
            $cached = $this->regionCache->get($hostKey);

            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            // Only the headers that identify the caller are forwarded. Content-Type
            // and Accept describe the Twirp body and would be wrong here; this
            // endpoint is plain JSON.
            $response = $this->httpClient->sendRequest(
                $this->requestFactory->createRequest('GET', $origin . self::REGIONS_PATH)
                    ->withHeader('Authorization', $jwtHeader)
                    ->withHeader('User-Agent', self::userAgent())
                    ->withHeader(self::REQUEST_ID_HEADER, $requestId)
                    ->withHeader('Accept', 'application/json')
            );
        } catch (\Throwable) {
            // Deliberately broader than ClientExceptionInterface. Discovery runs only
            // after a request has already failed, and its single job is to produce
            // fallback hosts or none. Letting anything escape here would replace the
            // error the caller needs to see with one from the recovery attempt.
            return [];
        }

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return [];
        }

        $urls = self::regionUrls((string) $response->getBody());

        $this->regionCache->put($hostKey, $urls, Failover::parseMaxAge($response->getHeaderLine('Cache-Control')));

        return $urls;
    }

    /**
     * Pulls the region URLs out of a /settings/regions payload, tolerating anything
     * unexpected in it. This body is the reason failover is restricted to LiveKit
     * Cloud hosts: every URL here becomes a candidate to receive the caller's token.
     *
     * @return list<string>
     */
    private static function regionUrls(string $body): array
    {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded) || !isset($decoded['regions']) || !is_array($decoded['regions'])) {
            return [];
        }

        $urls = [];

        foreach ($decoded['regions'] as $region) {
            if (is_array($region) && isset($region['url']) && is_string($region['url']) && $region['url'] !== '') {
                $urls[] = $region['url'];
            }
        }

        return $urls;
    }

    /**
     * Turns a Twirp error envelope into the most specific exception it fits.
     *
     * The SIP-aware subclass is chosen here, from the payload, rather than by the
     * caller naming an exception class. Only CreateSIPParticipant and
     * TransferSIPParticipant ever produce SIP status metadata, but this is the one
     * place that parses the envelope, so this is where the decision belongs — and
     * a caller can never accidentally ask for the wrong class.
     */
    private static function errorFromResponse(int $status, string $body): TwirpException
    {
        $exception = TwirpException::fromResponse($status, $body);
        $meta = $exception->getMeta();

        if (isset($meta['sip_status_code']) || isset($meta['sip_status'])) {
            // Built from the fields $exception already parsed out of $body, rather
            // than parsing the same JSON envelope again via SipCallError::fromResponse().
            // Equivalent result -- SipCallError adds no parsing of its own, only the
            // getters above -- without decoding $body twice.
            return new SipCallError($exception->getMessage(), $exception->getTwirpCode(), $status, $meta);
        }

        return $exception;
    }

    /**
     * LiveKit accepts ws:// and wss:// hosts for convenience because that is what
     * client SDKs are configured with; the HTTP API lives on the same origin.
     */
    private static function normalizeHost(string $host): string
    {
        $host = trim($host);

        if ($host === '') {
            throw ConfigurationException::missingHost();
        }

        if (str_starts_with($host, 'wss://')) {
            $host = 'https://' . substr($host, 6);
        } elseif (str_starts_with($host, 'ws://')) {
            $host = 'http://' . substr($host, 5);
        }

        return rtrim($host, '/');
    }

    /**
     * The bare hostname of a normalized host URL, for the cloud-domain check.
     * A host we cannot parse is not one we will fail over from.
     */
    private static function hostnameOf(string $host): string
    {
        $parts = parse_url($host);

        return is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
    }

    /**
     * A UUID v4 for X-Livekit-Request-Id. No dependency needed for an opaque id.
     */
    private static function requestId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
