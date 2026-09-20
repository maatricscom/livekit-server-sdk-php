# LiveKit Server SDK for PHP

[![CI](https://github.com/maatrics/livekit-server-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/maatrics/livekit-server-sdk-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/maatrics/livekit-server-sdk-php.svg)](https://packagist.org/packages/maatrics/livekit-server-sdk-php)
[![PHP Version](https://img.shields.io/packagist/php-v/maatrics/livekit-server-sdk-php.svg)](https://packagist.org/packages/maatrics/livekit-server-sdk-php)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

A [LiveKit](https://livekit.io) server SDK for PHP, written from scratch and mirroring the structure and
ergonomics of the official [Node.js server SDK](https://github.com/livekit/node-sdks). It talks to a
LiveKit deployment over Twirp RPC and mints/verifies the JWTs LiveKit uses for both client access tokens
and webhooks.

- **`RoomServiceClient`** — create, list and manage rooms and participants (all 14 `livekit.RoomService` RPCs)
- **`EgressClient`** — start, update and stop recordings and streams (all 10 `livekit.Egress` RPCs)
- **`IngressClient`** — configure inbound RTMP/WHIP/SRT feeds (all 4 `livekit.Ingress` RPCs)
- **`SipClient`** — SIP trunks, dispatch rules and call control (all 16 `livekit.SIP` RPCs)
- **`AgentDispatchClient`** — dispatch and manage agent jobs (all 3 `livekit.AgentDispatchService` RPCs)
- **`ConnectorClient`** — bridge WhatsApp and Twilio calls into rooms (all 5 `livekit.Connector` RPCs, LiveKit Cloud only)
- **`AccessToken`** / **`TokenVerifier`** — mint and verify the HS256 JWTs LiveKit uses for room access
- **`WebhookReceiver`** — verify and parse LiveKit's server-to-server webhooks

No official PHP SDK exists upstream; LiveKit's own ecosystem page points to a community package
(`agence104/livekit-server-sdk`) instead. This package can be installed alongside that one — see
[Migrating from `agence104/livekit-server-sdk`](#migrating-from-agence104livekit-server-sdk) below.

## Requirements

- PHP 8.4 or later
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client implementation and matching
  [PSR-17](https://www.php-fig.org/psr/psr-17/) factories. This package does not bundle one — it discovers
  whatever is installed via [`php-http/discovery`](https://github.com/php-http/discovery), or accepts one
  you construct yourself.

Pick one when installing:

```bash
composer require maatrics/livekit-server-sdk-php guzzlehttp/guzzle
```

```bash
composer require maatrics/livekit-server-sdk-php symfony/http-client nyholm/psr7
```

If your application is already built on Laravel or Symfony, you can stop there and skip the second
argument entirely — both frameworks ship a PSR-18 client (Laravel via `guzzlehttp/guzzle` in its default
`composer.json`, Symfony via `symfony/http-client`) and discovery will find it automatically. Only install
one of the lines above when starting from a bare PHP project.

## Quickstart

`LiveKitAPI` is a facade over the six service clients, sharing one set of credentials and one HTTP
client across all of them. Each service client also works standalone with the identical constructor
signature, in case you only need one of them:

```php
use LiveKit\LiveKitAPI;
use LiveKit\Options\CreateRoomOptions;

$livekit = new LiveKitAPI('https://my-project.livekit.cloud', 'API_KEY', 'API_SECRET');

$room = $livekit->room->createRoom(new CreateRoomOptions(name: 'my-room', emptyTimeout: 300));

foreach ($livekit->room->listRooms() as $existing) {
    echo $existing->getName(), PHP_EOL;
}
```

Every constructor argument is optional, so in most deployments you can simply write `new LiveKitAPI()`
and configure it through the environment — see [Credentials](#credentials) below.

List methods (`listRooms()`, `listEgress()`, `listSipInboundTrunk()`, and so on) return plain PHP arrays
rather than a generated protobuf `RepeatedField`; every other method returns the generated
`LiveKit\Proto\*` message for that RPC's response.

## Credentials

The host comes from the `host` argument or `LIVEKIT_URL`. For authentication you need **either** an API
key and secret **or** a pre-signed token:

```php
// Key and secret — the usual choice for a backend. The host needs its scheme:
// http(s), or ws(s), which is rewritten since the HTTP API shares the origin.
new LiveKitAPI('https://my-project.livekit.cloud', 'API_KEY', 'API_SECRET');

// A pre-signed token, for somewhere the API secret must not go. Its grants have
// to cover the calls you make with it.
new LiveKitAPI(
    host: 'https://my-project.livekit.cloud',
    options: new LiveKit\Options\ClientOptions(token: $token),
);

// Nothing passed: read from LIVEKIT_URL plus either LIVEKIT_TOKEN, or
// LIVEKIT_API_KEY and LIVEKIT_API_SECRET.
new LiveKitAPI();
```

**The environment is read only when you pass no credential at all.** It is not a per-field fallback: an
API key given to the constructor is *not* completed with a secret from the environment, and an ambient
`LIVEKIT_TOKEN` does *not* stand in for credentials you passed in. Mixing the two is how a process ends up
authenticating as something nobody chose, so a half-supplied credential is an error rather than a guess.
Every other LiveKit server SDK draws the line in the same place.

When the environment is used, `LIVEKIT_TOKEN` wins: a token is a complete credential on its own, so
`LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET` are not read at all.

## Access tokens

Client SDKs (JavaScript, Swift, Android, …) join a room with a short-lived JWT your server mints. Build
one with `AccessToken` and a `VideoGrant`:

```php
use LiveKit\AccessToken;
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Grants\VideoGrant;

$token = new AccessToken('API_KEY', 'API_SECRET', new AccessTokenOptions(
    identity: 'alice',
    name: 'Alice',
    ttl: '6h',
));

$token->addGrant(new VideoGrant(roomJoin: true, room: 'my-room'));

echo $token->toJwt();
```

`ttl` accepts either an integer number of seconds or a duration string with an `s`/`m`/`h`/`d` suffix
(`'45s'`, `'10m'`, `'6h'`, `'2d'`), matching the Node SDK. It defaults to 6 hours.

> [!WARNING]
> **`canPublish`, `canSubscribe`, `canPublishData`, `canUpdateOwnMetadata`, `canSubscribeMetrics` and
> `canManageAgentSession` are three-valued, not boolean.** Leaving one `null` (the default) *omits* the
> claim and lets the server apply its own default — which **allows both publish and subscribe**. Passing
> `false` is an explicit denial that is actually sent over the wire. These are not interchangeable: a
> subscribe-only token that leaves `canPublish` as `null` instead of `false` still lets that participant
> publish, because the server never saw a denial. If you mean to deny a permission, pass `false`
> explicitly — do not rely on omission.

## Webhooks

LiveKit POSTs an event to your webhook URL whenever something happens in a room (participants joining,
egress finishing, and so on). `WebhookReceiver` verifies the request's signature and returns the decoded
`LiveKit\Proto\WebhookEvent`.

```php
// Plain PHP
$receiver = new LiveKit\WebhookReceiver('API_KEY', 'API_SECRET');
$event = $receiver->receive(
    file_get_contents('php://input'),
    $_SERVER['HTTP_AUTHORIZATION'] ?? null,
);
```

```php
// Laravel
$event = $receiver->receive($request->getContent(), $request->header('Authorization'));
```

```php
// Symfony
$event = $receiver->receive($request->getContent(), $request->headers->get('Authorization'));
```

`$event->getEvent()` gives you the event name as a plain string (compare it against
`LiveKit\Enums\WebhookEventType` cases, or use `WebhookEventType::tryFrom()` since a newer LiveKit server
may send an event name this package does not know about yet).

> [!WARNING]
> **The signature covers the exact body bytes LiveKit sent, not their meaning.** Never decode the JSON and
> re-encode it before calling `receive()` — the hash will not match, and verification will fail. This is
> not a hypothetical: Go's `protojson` output is deliberately not byte-stable (an internal `detrand`
> package perturbs whitespace between calls), and PHP's `json_encode()` escapes bytes Go emits raw —
> non-ASCII characters become `\uXXXX` and `/` becomes `\/`. Room names and metadata routinely contain
> both. Always pass `receive()` the unmodified request body, and make sure nothing upstream — a
> body-parsing middleware, a proxy, a logging layer — has already consumed or rewritten it. In Laravel and
> Symfony, `getContent()` is safe precisely because it returns the raw stream contents rather than the
> framework's parsed representation.

Pass `skipAuth: true` to `receive()` only in local development, when you have no signing secret to hand.

## Timeouts

`ClientOptions::$requestTimeout` (in seconds, default 10) is sent as the `X-Twirp-Timeout-Ms` header, a
hint to the *server* for how long it may spend on the request — the Twirp protocol itself defines no such
header, so this is honoured on a best-effort basis rather than a guarantee. It is not, and cannot be, a
client-side socket timeout, because **PSR-18 defines no per-request timeout at all**. If the server never
responds — a dropped connection, a network partition — a PSR-18 client with no timeout configured will
hang indefinitely, not throw. Configure a client-side timeout yourself on whatever HTTP client you inject:

```php
use GuzzleHttp\Client;

$livekit = new LiveKit\LiveKitAPI(
    host: 'https://my-project.livekit.cloud',
    apiKey: 'API_KEY',
    apiSecret: 'API_SECRET',
    httpClient: new Client(['timeout' => 40]),
);
```

If you let `php-http/discovery` find a client for you (the default when you don't pass `httpClient`), it
uses that client's own defaults, which may have no timeout either — pass an explicit client whenever you
need a bounded worst case.

A `requestTimeout` of zero or less sends no header at all, leaving the server to apply its own default:
`0` would otherwise tell it that it has no time, and a negative number is not a deadline.

> [!WARNING]
> An application that dials with `waitUntilAnswered` must give its HTTP client a socket timeout longer
> than `SipClient::dialRequestTimeout()`. That call has to stay open while the callee's phone rings, and
> its floor is the ringing timeout plus a margin (32 seconds for the defaults) — a client-side timeout
> shorter than that aborts the request while the phone is still ringing, before LiveKit's own deadline
> ever has a chance to fire.

## WhatsApp and Twilio calls

`ConnectorClient` bridges a call from WhatsApp or Twilio into a LiveKit room. It is a **LiveKit Cloud**
service — `livekit.Connector` has no implementation in the open-source server, so these five RPCs only
answer on a Cloud project.

LiveKit does not store your Meta credentials, so every WhatsApp request carries them:

```php
use LiveKit\Options\DialWhatsAppCallOptions;

$call = $livekit->connector->dialWhatsAppCall(
    whatsappPhoneNumberId: 'PHONE_NUMBER_ID',
    whatsappToPhoneNumber: '+15551234567',
    whatsappApiKey: 'META_API_KEY',
    whatsappCloudApiVersion: '23.0',
    options: new DialWhatsAppCallOptions(roomName: 'support-call', ringingTimeout: 45),
);

echo $call->getWhatsappCallId(), ' in ', $call->getRoomName(), PHP_EOL;
```

An inbound call arrives on Meta's webhook with an SDP offer, which you hand to `acceptWhatsAppCall()`:

```php
use LiveKit\Options\AcceptWhatsAppCallOptions;

$accepted = $livekit->connector->acceptWhatsAppCall(
    whatsappPhoneNumberId: 'PHONE_NUMBER_ID',
    whatsappApiKey: 'META_API_KEY',
    whatsappCloudApiVersion: '23.0',
    whatsappCallId: $event['call_id'],
    sdp: $sdpFromWebhook,
    options: new AcceptWhatsAppCallOptions(roomName: 'support-call', waitUntilAnswered: true),
);
```

Twilio needs only the direction and a room, and gives you back the URL to point a media stream at:

```php
use LiveKit\Proto\ConnectTwilioCallRequest\TwilioCallDirection;

$twilio = $livekit->connector->connectTwilioCall(
    TwilioCallDirection::TWILIO_CALL_DIRECTION_INBOUND,
    'support-call',
);

echo $twilio->getConnectUrl(), PHP_EOL; // wss://...
```

> [!NOTE]
> `waitUntilAnswered` holds the request open while the call rings, so — exactly as with
> `SipClient::createSipParticipant()` — the SDK raises the request timeout to the ring window plus a
> margin, and your HTTP client needs a socket timeout longer than that. See [Timeouts](#timeouts).

## Region failover

On LiveKit Cloud, a request that fails for a reason another region might not share is automatically
retried against one. The client asks your project's host for its region list, then replays the request
against the next region it has not tried yet, up to three attempts with exponential backoff between them.
It is on by default:

```php
$livekit = new LiveKit\LiveKitAPI(
    host: 'https://my-project.livekit.cloud',
    apiKey: 'API_KEY',
    apiSecret: 'API_SECRET',
    options: new LiveKit\Options\ClientOptions(failover: false), // opt out
);
```

What counts as retryable is deliberately narrow:

| Outcome | Retried? | Why |
| --- | --- | --- |
| Transport error (connection refused, reset, timeout) | yes | the region may be unreachable |
| HTTP 5xx | yes | the region may be unhealthy |
| HTTP 4xx | no | the request is what is wrong; another region answers the same |
| HTTP 451 | **redirected** | a region pin, not a failure — see below |
| `SipCallError` | **no** | see below |

A `SipCallError` arrives as an HTTP 500, so nothing but its metadata distinguishes it from a server fault.
But SIP status metadata means the call reached the far end and the far end answered — busy, declined, no
answer. Another region cannot produce a better answer; it would just dial the number a second time, ring a
real phone again, and bill for it. This SDK therefore treats a `SipCallError` as final. (The Node SDK does
retry it; this is a deliberate difference.)

Two limits are worth knowing about:

- **Failover only ever engages for `*.livekit.cloud` hosts.** A replay sends your bearer token to an origin
  the SDK learned at runtime, from a server response, so the set of hosts that can receive it stays pinned
  to a domain LiveKit controls. Self-hosted deployments get a single attempt. The check is on the dotted
  suffix, so a lookalike domain such as `evil-livekit.cloud` is not eligible.
- **A request timeout below 5 seconds disables it.** A retry that short is unlikely to complete, and many
  clients retrying in lockstep across regions is worse than one failing fast.

Every attempt carries the same `X-Livekit-Request-Id`, so LiveKit can recognise a replay as the same
request rather than a new one.

### Region pinning

A LiveKit Cloud project can be pinned to a set of regions. A request that reaches a region the project is
*not* pinned to is turned away by middleware with an **HTTP 451**, before it is served — the body is plain
text rather than a Twirp error, and it names no destination.

That is a redirect, not a failure, and the SDK follows it: it rediscovers regions (a pinned project's
`/settings/regions` lists only the ones it is allowed) and sends the request to one of those. You do not
need to configure anything, and unlike failover it is **not disabled by `failover: false`** — a pinned
project has no other region that would answer, so declining to follow the redirect would turn a working
call into an error with nothing gained.

It is bounded: at most two redirects per call, and a host is never tried twice. If rediscovery turns up no
region the project can reach — a project pinned to a region that is down, say — the 451 is raised as a
`TwirpException` with LiveKit's own message. A redirect does not consume the failover attempts, so a call
can be redirected by a pin and still retried if the region it lands on is unhealthy.

The same domain restriction applies: a 451 from anything that is not a `*.livekit.cloud` host is treated
as an ordinary error, because region pinning is a LiveKit Cloud mechanism and following it would send your
token to a host named by whatever produced the response.

> [!NOTE]
> No official LiveKit SDK implements this yet — LiveKit's own SDK test server specifies the behaviour, and
> this implementation is written and tested against that specification.

The region list is cached for as long as its `Cache-Control: max-age` allows, and shared by the five
clients behind one `LiveKitAPI`. Under PHP-FPM each request is a fresh process, so that cache starts cold
every time and a failover costs one extra request to discover regions — on the failure path only. In a
long-running process (a queue worker, Swoole, RoadRunner) it is reused for its full lifetime.

## Error handling

Every failure this SDK reports — a rejected request, an unreachable server, a response it cannot decode,
a configuration it will not sign — implements `LiveKit\Exceptions\LiveKitException`, so catching that alone
covers them all. (Passing an argument of the wrong type still raises PHP's own `TypeError`, as anywhere
else.) RPC failures specifically throw `TwirpException`, which carries the
Twirp error code, the HTTP status and any metadata LiveKit attached:

```php
use LiveKit\Exceptions\SipCallError;
use LiveKit\Exceptions\TwirpException;

try {
    $livekit->room->deleteRoom('my-room');
} catch (TwirpException $e) {
    echo $e->getTwirpCode();   // e.g. not_found
    echo $e->getHttpStatus();  // e.g. 404
    print_r($e->getMeta());
}
```

`SipClient::createSipParticipant()` and `SipClient::transferSipParticipant()` can additionally fail with
`SipCallError` (a `TwirpException` subclass), which adds `getSipStatusCode()` and `getSipStatus()` for the
SIP-specific failure reported by the far end of the call — catch it before the general `TwirpException` if
you need that detail:

```php
try {
    $livekit->sip->createSipParticipant(/* ... */);
} catch (SipCallError $e) {
    echo $e->getSipStatusCode(); // e.g. 486
    echo $e->getSipStatus();     // e.g. "Busy Here"
} catch (TwirpException $e) {
    // any other Twirp failure
}
```

Configuration mistakes (a missing host, a missing or too-short API secret) throw
`LiveKit\Exceptions\ConfigurationException` at construction time, before any network call is made.

Verifying rather than making a call has its own two: `TokenVerificationException` for a token that does
not check out — a bad signature, an expired or not-yet-valid window, a token that cannot be parsed, or one
minted for a different API key — and `WebhookVerificationException` for a webhook this SDK will not
accept. Both keep the underlying cause as the previous exception, so a caller that wants to tell an
expired token from a forged one still can:

```php
use LiveKit\Exceptions\TokenVerificationException;

try {
    $claims = (new LiveKit\TokenVerifier())->verify($jwt);
} catch (TokenVerificationException $e) {
    if ($e->getPrevious() instanceof Firebase\JWT\ExpiredException) {
        // ask for a fresh token
    }
}
```

The verifier is handed one algorithm, HS256, rather than the one the token names for itself — a token
signed with the same secret under HS384 or HS512 is rejected, and so is one claiming `alg: none`.

## Room configuration in a token

A token can carry a `RoomConfiguration`, applied when its holder creates the room:

```php
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Proto\RoomConfiguration;

$token = new AccessToken('API_KEY', 'API_SECRET', new AccessTokenOptions(
    identity: 'alice',
    roomConfig: (new RoomConfiguration())->setEmptyTimeout(300),
));
```

> [!WARNING]
> A JWT is signed, not encrypted, so everything in it is readable by whoever holds it. If the room
> configuration's egress carries an S3 secret, a GCP service account, an Azure account key or a stream
> output, `toJwt()` refuses to sign rather than publish those to the participant — the same rule LiveKit's
> Go SDK applies. `AccessToken::allowSensitiveCredentials()` opts out, for a token that genuinely stays
> server-side.

## Static analysis

The generated `LiveKit\Proto\*` classes are analysed cleanly at PHPStan's max level, including from your
own code. Repeated fields carry generic types — `getEnabledCodecs()` is documented as
`RepeatedField<\LiveKit\Proto\Codec>` — so iterating one gives you the element type rather than `mixed`:

```php
foreach ($room->getEnabledCodecs() as $codec) {
    echo $codec->getMime(), PHP_EOL; // $codec is a Codec, not mixed
}
```

Setters are natively typed too, so passing the wrong thing is an error your analyser reports rather than
one protobuf raises at runtime:

```php
$room->setName(123);        // Parameter #1 $var of method Room::setName() expects string
$room->setEmptyTimeout('x'); // ...expects int
```

Both come from the protoc version the classes were generated with, which is why
`bin/generate-protos.sh` requires 36.2 or newer.

## Dependency injection and testing

Each service client implements an interface in `LiveKit\Contracts`, so application code can depend on the
capability rather than on this package's concrete class:

| Interface | Implemented by |
| --- | --- |
| `RoomServiceClientInterface` | `RoomServiceClient` |
| `EgressClientInterface` | `EgressClient` |
| `IngressClientInterface` | `IngressClient` |
| `SipClientInterface` | `SipClient` |
| `AgentDispatchClientInterface` | `AgentDispatchClient` |
| `ConnectorClientInterface` | `ConnectorClient` |

Type-hint the interface and let the container supply the client:

```php
use LiveKit\Contracts\RoomServiceClientInterface;

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
```

```php
// Container wiring: one LiveKitAPI, its clients bound to their interfaces.
$livekit = new LiveKit\LiveKitAPI();

$container->set(RoomServiceClientInterface::class, $livekit->room);
$container->set(SipClientInterface::class, $livekit->sip);
```

In tests this lets you stand in for the service without touching HTTP at all:

```php
$rooms = $this->createStub(RoomServiceClientInterface::class);
$rooms->method('createRoom')->willReturn((new Room())->setSid('RM_test'));

self::assertSame('RM_test', (new RoomProvisioner($rooms))->provision('my-room'));
```

The interfaces declare every method its client has — nothing is available on the class but missing from
the contract — and are covered by this package's backward-compatibility promise, so a mock written against
one keeps working across minor versions.

If you would rather exercise the real client against a real transport, inject a PSR-18 double instead of
mocking the interface: the clients accept one, and it is what this package's own test suite uses. See
[Timeouts](#timeouts) for how the HTTP client is supplied.

## Migrating from `agence104/livekit-server-sdk`

If you're moving from the existing community SDK, the biggest difference is namespacing — everything else
maps over fairly directly.

| | `agence104/livekit-server-sdk` | `maatrics/livekit-server-sdk-php` (this package) |
|---|---|---|
| Generated protobuf classes | Global `Livekit\` namespace | `LiveKit\Proto\` |
| Service clients | `Agence104\LiveKit\RoomServiceClient`, etc. | `LiveKit\Services\RoomServiceClient`, etc. |
| SIP support | Not present | `LiveKit\Services\SipClient` (all 16 RPCs) |
| Agent dispatch | Not present | `LiveKit\Services\AgentDispatchClient` (all 3 RPCs) |
| Access tokens | `Agence104\LiveKit\AccessToken` | `LiveKit\AccessToken` |
| Webhooks | `Agence104\LiveKit\WebhookReceiver` | `LiveKit\WebhookReceiver` |

Because `agence104/livekit-server-sdk` puts its generated protobuf classes in the **global** `Livekit\`
namespace and this package generates into `LiveKit\Proto\`, the two do not collide. That means you can
require both packages in the same project and migrate incrementally, call site by call site, rather than
in one atomic cutover.

## Supported LiveKit protocol version

This package is generated from `livekit/protocol` **`v1.52.0`**. To regenerate against a newer tag, see
[`CONTRIBUTING.md`](CONTRIBUTING.md#regenerating-the-protobuf-classes).

## Contributing

Bug reports and pull requests are welcome — see [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to run the
test suite, regenerate the protobuf classes, and what the release checklist looks like.

## License

Licensed under the [Apache License, Version 2.0](LICENSE).
