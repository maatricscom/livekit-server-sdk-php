# LiveKit Server SDK for PHP

[![CI](https://github.com/maatrics/livekit-server-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/maatrics/livekit-server-sdk-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/maatrics/livekit-server-sdk.svg)](https://packagist.org/packages/maatrics/livekit-server-sdk)
[![PHP Version](https://img.shields.io/packagist/php-v/maatrics/livekit-server-sdk.svg)](https://packagist.org/packages/maatrics/livekit-server-sdk)
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
- **`AccessToken`** / **`TokenVerifier`** — mint and verify the HS256 JWTs LiveKit uses for room access
- **`WebhookReceiver`** — verify and parse LiveKit's server-to-server webhooks

No official PHP SDK exists upstream; LiveKit's own ecosystem page points to a community package
(`agence104/livekit-server-sdk`) instead. This package can be installed alongside that one — see
[Migrating from `agence104/livekit-server-sdk`](#migrating-from-agence104livekit-server-sdk) below.

## Requirements

- PHP 8.3 or later
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client implementation and matching
  [PSR-17](https://www.php-fig.org/psr/psr-17/) factories. This package does not bundle one — it discovers
  whatever is installed via [`php-http/discovery`](https://github.com/php-http/discovery), or accepts one
  you construct yourself.

Pick one when installing:

```bash
composer require maatrics/livekit-server-sdk guzzlehttp/guzzle
```

```bash
composer require maatrics/livekit-server-sdk symfony/http-client nyholm/psr7
```

If your application is already built on Laravel or Symfony, you can stop there and skip the second
argument entirely — both frameworks ship a PSR-18 client (Laravel via `guzzlehttp/guzzle` in its default
`composer.json`, Symfony via `symfony/http-client`) and discovery will find it automatically. Only install
one of the lines above when starting from a bare PHP project.

## Quickstart

`LiveKitClient` is a facade over the five service clients, sharing one set of credentials and one HTTP
client across all of them. Each service client also works standalone with the identical constructor
signature, in case you only need one of them:

```php
use LiveKit\LiveKitClient;
use LiveKit\Options\CreateRoomOptions;

$livekit = new LiveKitClient('https://my-project.livekit.cloud', 'API_KEY', 'API_SECRET');

$room = $livekit->room->createRoom(new CreateRoomOptions(name: 'my-room', emptyTimeout: 300));

foreach ($livekit->room->listRooms() as $existing) {
    echo $existing->getName(), PHP_EOL;
}
```

Every constructor argument is optional and falls back to the `LIVEKIT_URL`, `LIVEKIT_API_KEY` and
`LIVEKIT_API_SECRET` environment variables, so in most deployments you can simply write
`new LiveKitClient()`. List methods (`listRooms()`, `listEgress()`, `listSipInboundTrunk()`, and so on)
return plain PHP arrays rather than a generated protobuf `RepeatedField`; every other method returns the
generated `LiveKit\Proto\*` message for that RPC's response.

## Access tokens

Client SDKs (JavaScript, Swift, Android, …) join a room with a short-lived JWT your server mints. Build
one with `AccessToken` and a `VideoGrant`:

```php
use LiveKit\AccessToken;
use LiveKit\AccessTokenOptions;
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

`ClientOptions::$requestTimeout` (in seconds, default 10) is sent to LiveKit as the `X-Twirp-Timeout-Ms`
header. It tells the *server* how long it may spend on the request — it is not a client-side socket
timeout, and cannot be, because **PSR-18 defines no per-request timeout at all**. If the server never
responds — a dropped connection, a network partition — a PSR-18 client with no timeout configured will
hang indefinitely, not throw. Configure a client-side timeout yourself on whatever HTTP client you inject:

```php
use GuzzleHttp\Client;

$livekit = new LiveKit\LiveKitClient(
    host: 'https://my-project.livekit.cloud',
    apiKey: 'API_KEY',
    apiSecret: 'API_SECRET',
    httpClient: new Client(['timeout' => 15]),
);
```

If you let `php-http/discovery` find a client for you (the default when you don't pass `httpClient`), it
uses that client's own defaults, which may have no timeout either — pass an explicit client whenever you
need a bounded worst case.

## Error handling

Every exception this SDK throws implements `LiveKit\Exceptions\LiveKitException`, so catching that alone
handles anything the SDK can raise. RPC failures specifically throw `TwirpException`, which carries the
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

## Migrating from `agence104/livekit-server-sdk`

If you're moving from the existing community SDK, the biggest difference is namespacing — everything else
maps over fairly directly.

| | `agence104/livekit-server-sdk` | `maatrics/livekit-server-sdk` (this package) |
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
