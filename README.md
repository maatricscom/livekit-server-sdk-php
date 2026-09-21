# LiveKit Server SDK for PHP

[![CI](https://github.com/maatricscom/livekit-server-sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/maatricscom/livekit-server-sdk-php/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/maatrics/livekit-server-sdk-php.svg)](https://packagist.org/packages/maatrics/livekit-server-sdk-php)
[![PHP Version](https://img.shields.io/packagist/php-v/maatrics/livekit-server-sdk-php.svg)](https://packagist.org/packages/maatrics/livekit-server-sdk-php)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg)](LICENSE)

A [LiveKit](https://livekit.io) server SDK for PHP, written from scratch and mirroring the structure and
ergonomics of the official [Node.js server SDK](https://github.com/livekit/node-sdks). It talks to a
LiveKit deployment over Twirp RPC and mints/verifies the JWTs LiveKit uses for both client access tokens
and webhooks.

- **`RoomServiceClient`** — create, list and manage rooms and participants (all 14 `livekit.RoomService` RPCs)
- **`EgressClient`** — start, update and stop recordings and streams (all 10 `livekit.Egress` RPCs)
- **`IngressClient`** — bring an RTMP, WHIP or pulled-URL feed into a room (all 4 `livekit.Ingress` RPCs)
- **`SipClient`** — SIP trunks, dispatch rules and call control (all 16 `livekit.SIP` RPCs)
- **`AgentDispatchClient`** — dispatch and manage agent jobs (all 3 `livekit.AgentDispatchService` RPCs)
- **`ConnectorClient`** — bridge WhatsApp and Twilio calls into rooms (all 5 `livekit.Connector` RPCs,
  LiveKit Cloud only)
- **`AccessToken`** / **`TokenVerifier`** — mint and verify the HS256 JWTs LiveKit uses for room access
- **`WebhookReceiver`** — verify and parse LiveKit's server-to-server webhooks

As of v0.1.0 (September 2026) LiveKit publishes server SDKs for Go, Ruby, Python and Kotlin but none for
PHP, and its own ecosystem page points to a community package (`agence104/livekit-server-sdk`) instead.
Moving off it is one cutover rather than a gradual one, for a reason that is not about either package's
code — see [Migrating from `agence104/livekit-server-sdk`](#migrating-from-agence104livekit-server-sdk)
below.

## Requirements

- PHP 8.4 or later
- A [PSR-18](https://www.php-fig.org/psr/psr-18/) HTTP client implementation and matching
  [PSR-17](https://www.php-fig.org/psr/psr-17/) factories. This package does not bundle one. It names them as
  virtual requirements instead, so Composer supplies one when your project has none and leaves the one you
  already have alone.
- Optionally, [`ext-protobuf`](https://pecl.php.net/package/protobuf) — see below. Without it the pure-PHP
  protobuf runtime that ships with `google/protobuf` is used, which is the supported default.

```bash
composer require maatrics/livekit-server-sdk-php
```

That is the whole install. A project that already has a PSR-18 client keeps it — Laravel ships
`guzzlehttp/guzzle`, Symfony ships `symfony/http-client`, and either is used as it stands. A project with
none is asked to allow the [`php-http/discovery`](https://github.com/php-http/discovery) plugin, which then
installs `symfony/http-client` and `nyholm/psr7`.

To pick the client yourself rather than take that default, name it and it is used instead:

```bash
composer require maatrics/livekit-server-sdk-php guzzlehttp/guzzle
```

Declining the plugin is the one case worth knowing about. The install still succeeds, because
`php-http/discovery` satisfies the requirement on paper, but nothing is left that can send a request and the
first call raises `Http\Discovery\Exception\NotFoundException`. Installing any client clears it.

### The protobuf C extension

`ext-protobuf` replaces the pure-PHP protobuf runtime with a C one, and is worth installing if you send a
lot of traffic. Nothing about this package's API changes.

**It must be 5.34 or newer**, and `composer.json` declares a conflict below that so you find out at install
time rather than at a call site. The reason is that the extension *shadows* `google/protobuf`: once it is
loaded, its own `Google\Protobuf\Internal\*` classes are used and the Composer package's are never
autoloaded, so the `^5.36` requirement on the package constrains nothing. Generated code here is produced
by protoc 36, whose getters for `optional` int64 fields call `GPBUtil::compatibleInt64()` — a method the
extension gained in 5.34.0. Against an older one — 4.32.1 was Alpine's package at the time of writing —
those getters raise
`Call to undefined method`. The conflict is set at 5.34 rather than 5.36 because it says what is broken;
`^5.36` on the Composer package says what this SDK is built and tested against.

The two runtimes are not identical below the API, and where they differ this package decides rather than
letting the installed runtime decide. The clearest case is a malformed webhook body: the pure-PHP parser
accepts a JSON array and hands back a default message, `ext-protobuf` accepts an empty body and does the
same. `WebhookReceiver` rejects both, on both runtimes. The test suite runs against the extension as well
as the pure-PHP runtime on every push, so this stays true.

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

Most list methods (`listRooms()`, `listParticipants()`, `listSipInboundTrunk()`, and so on) return plain
PHP arrays rather than a generated protobuf `RepeatedField`; every other method returns the generated
`LiveKit\Proto\*` message for that RPC's response.

The exception is a response that carries something besides the items. `ListEgressResponse` and
`ListIngressResponse` also carry `next_page_token`, and unwrapping them to an array would throw it away —
so `listEgress()` and `listIngress()` hand back the message, exactly as LiveKit's Go, Python and Ruby SDKs
do. Every other list response has one field and nothing is lost.

### Paginated lists

`ListEgress` and `ListIngress` can answer in pages, so the three calls below are three answers to what you
want done about that.

```php
// One request, and the response the server built. The same call the Go, Python
// and Ruby SDKs give you, cursor and all.
$page   = $livekit->egress->listEgress();
$items  = $page->getItems();
$cursor = $page->getNextPageToken()?->getToken();

// One page at a time. The next is fetched only when you reach for it, so
// leaving the loop early leaves the remaining requests unmade.
foreach ($livekit->egress->iterateEgress() as $egress) {
    break;
}

// Every page, collected into one array.
$all = $livekit->egress->listAllEgress();
```

`listEgress()` is the one to reach for when the cursor has to outlive the process — a page of results
rendered with a link to the next one. Keep `$cursor`, and hand it back through
`new ListEgressOptions(pageToken: $cursor)`.

`listAllEgress()` is the convenient one, and it walks: an array that stopped at a page boundary would be
indistinguishable from a complete one, which is the bug that shape invites. `iterateEgress()` is the same
walk without building the array, for an early exit or a list too large to hold.

## Credentials

The host comes from the `host` argument or `LIVEKIT_URL`. For authentication you need **either** an API
key and secret **or** a pre-signed token:

```php
use LiveKit\LiveKitAPI;
use LiveKit\Options\ClientOptions;

// Key and secret — the usual choice for a backend. The host needs its scheme:
// http(s), or ws(s), which is rewritten since the HTTP API shares the origin.
new LiveKitAPI('https://my-project.livekit.cloud', 'API_KEY', 'API_SECRET');

// A pre-signed token, for somewhere the API secret must not go. Its grants have
// to cover the calls you make with it.
new LiveKitAPI(
    host: 'https://my-project.livekit.cloud',
    options: new ClientOptions(token: $token),
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

`receive()` throws a `WebhookVerificationException` for a missing or unverifiable token, a body whose
hash does not match the token's `sha256` claim, and a body that is not a JSON object. That last one is
checked by this package rather than left to the protobuf runtime, because the two runtimes are lenient
about different malformed bodies — the pure-PHP parser accepts a JSON array and `ext-protobuf` accepts an
empty body, each handing back a default message. Neither gets to decide what a webhook is here.

Note that constructing `WebhookReceiver` throws `ConfigurationException` when no key and secret are
available, which is a different failure from a rejected request: one is this server misconfigured, the
other is the request. `examples/webhook.php` answers them with 500 and 401 respectively.

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

> [!IMPORTANT]
> **Whatever bound you set is per attempt, not per call.** On a LiveKit Cloud host `failover` is on by
> default, so a retryable failure is replayed against other regions up to three times and each attempt
> starts your timeout again. The same failing call, measured against a live Cloud project:
>
> | | wall clock |
> |---|---|
> | `failover: false`, `requestTimeout: 10` | **7.8 s** |
> | default failover, `requestTimeout: 10` | **177 s** |
> | default failover, `requestTimeout: 30` | **182 s** |
>
> Note which knob did nothing. Tripling `requestTimeout` moved the total by three percent, because it is
> only the server-side hint described above — the time is spent in the retries, against regions slower to
> give up than the one first asked. If bounded latency matters more to you than surviving a bad region, as
> it does on a request serving a web page, set `new ClientOptions(failover: false)` and handle the failure
> yourself.

A `requestTimeout` of zero or less sends no header at all, leaving the server to apply its own default:
`0` would otherwise tell it that it has no time, and a negative number is not a deadline.

> [!WARNING]
> An application that dials with `waitUntilAnswered` must give its HTTP client a socket timeout longer
> than `SipClient::dialRequestTimeout()`. That call has to stay open while the callee's phone rings, and
> its floor is the ringing timeout plus a margin (32 seconds for the defaults) — a client-side timeout
> shorter than that aborts the request while the phone is still ringing, before LiveKit's own deadline
> ever has a chance to fire.

## Rooms and participants

`RoomServiceClient` is the one you will reach for most. Beyond `createRoom()`, `listRooms()` and
`deleteRoom()`, it manages who is in a room and what they are allowed to do:

```php
use LiveKit\Options\UpdateParticipantOptions;

foreach ($livekit->room->listParticipants('my-room') as $participant) {
    echo $participant->getIdentity(), PHP_EOL;
}

$alice = $livekit->room->getParticipant('my-room', 'alice');

// Partial: only the fields you pass are changed. Everything omitted is left as
// it is rather than cleared, so you do not have to read-modify-write.
$livekit->room->updateParticipant('my-room', 'alice', new UpdateParticipantOptions(
    name: 'Alice (host)',
    metadata: '{"role":"host"}',
));
```

Moderation is `mutePublishedTrack()` for one track and `removeParticipant()` for the participant:

```php
$livekit->room->mutePublishedTrack('my-room', 'alice', 'TR_abc123', true);

$livekit->room->removeParticipant('my-room', 'alice');
```

Removing someone also stops the token they already hold from letting them straight back in: the server
rejects tokens for that identity whose `nbf` precedes a cutoff, and picks now-plus-a-minute of leeway
when you do not choose one. Pass `revokeTokenTs` yourself to set that cutoff explicitly.

`updateSubscriptions()` changes what a participant receives, and `updateRoomMetadata()` sets
application state on the room itself — both are server-side, so no client has to cooperate:

```php
$livekit->room->updateSubscriptions('my-room', 'alice', ['TR_abc123'], false);

$livekit->room->updateRoomMetadata('my-room', '{"stage":"q-and-a"}');
```

`performRpc()` calls a method a *client* SDK registered, from your backend, and returns its reply —
the inverse of the rest of this package, where your server calls LiveKit.

> [!WARNING]
> `PerformRpcResponse` carries a payload and nothing else — the proto has no error field — and a call
> aimed at an identity that is not in the room comes back **successful with an empty payload** rather
> than raising. Measured against a live Cloud deployment, it returns in about 0.13 s and ignores the
> `responseTimeoutMs` you passed. So an empty payload does not tell you the client replied with nothing:
> it may equally mean there was no client. If that distinction matters, confirm the participant with
> `getParticipant()` first, or have the client answer with something that is never empty.

> [!NOTE]
> `forwardParticipant()` and `moveParticipant()` are LiveKit Cloud only; an open-source server answers
> `unimplemented`. They are not the same operation: forwarding copies a participant's tracks into a
> second room while they stay where they are, and moving relocates them, so they leave the first.

## Sending data to a room

`sendData()` delivers a payload to everyone in a room, or to named participants:

```php
use LiveKit\Options\SendDataOptions;
use LiveKit\Proto\DataPacket\Kind;

$livekit->room->sendData(
    'my-room',
    json_encode(['type' => 'announcement', 'body' => 'starting now'], JSON_THROW_ON_ERROR),
    Kind::RELIABLE,
    new SendDataOptions(destinationIdentities: ['alice'], topic: 'chat'),
);
```

Every packet carries a fresh 16-byte nonce, which is what `livekit_room.proto` asks an SDK to attach and
what lets the server recognise a duplicate. You do not need to supply one. The exception is a send whose
outcome you do not know — a request that timed out may or may not have arrived — where retrying under the
nonce of the original is the difference between the server discarding a duplicate and the room receiving
the message twice:

```php
use LiveKit\Options\SendDataOptions;
use LiveKit\Proto\DataPacket\Kind;

$payload = 'starting now';
$options = new SendDataOptions(topic: 'chat', nonce: random_bytes(16));

// If this times out, retry it with the same $options rather than new ones.
$livekit->room->sendData('my-room', $payload, Kind::RELIABLE, $options);
```

## Recording and streaming

`EgressClient` records or restreams. Five of its calls start one and differ only in what they capture:
`startRoomCompositeEgress()` for the room as a composed video, `startWebEgress()` for an arbitrary URL,
`startParticipantEgress()` for one participant, and `startTrackCompositeEgress()` / `startTrackEgress()`
for chosen tracks. A sixth, `startEgress()`, takes a `StartEgressRequest` you have built yourself —
LiveKit's newer unified shape, where the source is a `oneof` and the outputs are a list, rather than a
capture-specific call.

```php
use LiveKit\Options\EncodedOutputs;
use LiveKit\Options\RoomCompositeOptions;
use LiveKit\Proto\EncodedFileOutput;
use LiveKit\Proto\S3Upload;

$egress = $livekit->egress->startRoomCompositeEgress(
    'my-room',
    new EncodedOutputs(file: new EncodedFileOutput()
        ->setFilepath('my-room-{time}.mp4')
        ->setS3(new S3Upload()->setBucket('recordings')->setRegion('eu-central-1'))),
    new RoomCompositeOptions(layout: 'speaker'),
);

foreach ($livekit->egress->listEgress() as $running) {
    echo $running->getEgressId(), ' ', $running->getStatus(), PHP_EOL;
}

$livekit->egress->stopEgress($egress->getEgressId());
```

`EncodedOutputs` carries up to four destinations at once — a file, a stream, segments and images — and
fills the plural `*_outputs` arrays. Passing a single output object instead also fills the deprecated
singular field, for servers old enough to read only that.

> [!IMPORTANT]
> Storage credentials travel inside the egress request, which is why they are set on the upload object
> rather than configured once. Do not put them in an access token: a JWT is readable by whoever holds it,
> and `AccessToken::toJwt()` refuses to sign a room configuration whose egress carries them.

## Ingest

`IngressClient` takes an external feed into a room. The input type decides what the server hands back:

| input | what you get | transcoding |
|---|---|---|
| `RTMP_INPUT` | an RTMP url and a stream key | always |
| `WHIP_INPUT` | a WHIP endpoint | optional — `enableTranscoding: false` forwards the media as-is |
| `URL_INPUT` | nothing to connect to; LiveKit pulls from the `url` you give it | always |

```php
use LiveKit\Options\CreateIngressOptions;
use LiveKit\Options\ListIngressOptions;
use LiveKit\Options\UpdateIngressOptions;
use LiveKit\Proto\IngressInput;

$ingress = $livekit->ingress->createIngress(new CreateIngressOptions(
    inputType: IngressInput::RTMP_INPUT,
    name: 'studio feed',
    roomName: 'my-room',
    participantIdentity: 'rtmp-source',
));

echo $ingress->getUrl(), ' ', $ingress->getStreamKey(), PHP_EOL;

// Only the fields you pass are changed. Everything omitted keeps its current
// value rather than being cleared, which is why the options are all nullable.
$livekit->ingress->updateIngress($ingress->getIngressId(), new UpdateIngressOptions(
    participantName: 'Studio',
));

$livekit->ingress->listIngress(new ListIngressOptions(roomName: 'my-room'));
$livekit->ingress->deleteIngress($ingress->getIngressId());
```

`getState()?->getStatus()` reports progress: `ENDPOINT_INACTIVE` until something connects, then
`ENDPOINT_BUFFERING` and `ENDPOINT_PUBLISHING`. It ends at `ENDPOINT_COMPLETE` when the feed stops
cleanly, or `ENDPOINT_ERROR` if it was refused.

## Agent dispatch

`AgentDispatchClient` sends a **named** agent into a room. An agent worker that registered without a name
is dispatched automatically to every new room and is not addressable here — if a dispatch appears to do
nothing, that is usually why.

```php
use LiveKit\Options\CreateDispatchOptions;

$dispatch = $livekit->agentDispatch->createDispatch(
    'my-room',
    'my-agent',
    // Reaches the agent as job metadata: which customer, which language, which prompt.
    new CreateDispatchOptions(metadata: '{"locale":"tr"}'),
);

$livekit->agentDispatch->listDispatch('my-room');

$one = $livekit->agentDispatch->getDispatch(dispatchId: $dispatch->getId(), room: 'my-room');

$livekit->agentDispatch->deleteDispatch(dispatchId: $dispatch->getId(), room: 'my-room');
```

Dispatching a name no worker has registered is harmless — the request is recorded and never assigned.

`getDispatch()` is this package's own convenience: `livekit.AgentDispatchService` has no `GetDispatch`
rpc, so it is `ListDispatch` filtered by id, returning the dispatch or `null` rather than an array to
index. A dispatch that does not exist is `null` whichever way your deployment reports it — LiveKit Cloud
answers `not_found`, others return an empty list, and both arrive here as `null`. That is a deliberate
difference from the Node SDK, which checks only for the empty list and therefore throws in the very case
its own documentation says returns nothing.

Watch the argument order: `getDispatch()` and `deleteDispatch()` take the dispatch id first, while
`listDispatch()` takes the room. It mirrors the Node SDK and the proto's own field order, and it is why
named arguments are worth using here.

## SIP

`SipClient` is the largest of the six — 16 RPCs plus three convenience wrappers — but it is three ideas:
**trunks** say how LiveKit reaches your telephony provider, **dispatch rules** say where an incoming call
lands, and `createSipParticipant()` places an outgoing one.

### Trunks

An inbound trunk accepts calls to your numbers; an outbound trunk places them through your provider.

```php
use LiveKit\Options\CreateSipInboundTrunkOptions;
use LiveKit\Options\CreateSipOutboundTrunkOptions;

$inbound = $livekit->sip->createSipInboundTrunk(
    'support line',
    ['+15551234567'],
    new CreateSipInboundTrunkOptions(allowedAddresses: ['203.0.113.0/24']),
);

$outbound = $livekit->sip->createSipOutboundTrunk(
    'provider',
    'sip.provider.example',
    ['+15551234567'],
    new CreateSipOutboundTrunkOptions(authUsername: 'user', authPassword: 'secret'),
);
```

Each trunk has two ways to change it, and the difference matters:

| | what it sends | effect |
|---|---|---|
| `updateSipInboundTrunk($id, $trunk)` | the `replace` arm | wholesale — **fields left unset are cleared** |
| `updateSipInboundTrunkFields($id, $fields)` | the `update` arm | only what you pass; the rest is untouched |

The same pair exists for outbound trunks and for dispatch rules. Reach for `*Fields()` unless you really
mean to replace the record, because the wholesale form is how a trunk quietly loses its credentials.

### Dispatch rules

A rule decides which room an inbound call joins:

```php
use LiveKit\Options\CreateSipDispatchRuleOptions;
use LiveKit\Proto\SIPDispatchRule;
use LiveKit\Proto\SIPDispatchRuleIndividual;

// Every caller gets their own room, named from the prefix. SIPDispatchRuleDirect
// sends all callers to one named room instead; SIPDispatchRuleCallee keys the
// room on the number that was dialled.
$rule = new SIPDispatchRule()->setDispatchRuleIndividual(
    new SIPDispatchRuleIndividual()->setRoomPrefix('call-')
);

$livekit->sip->createSipDispatchRule($rule, new CreateSipDispatchRuleOptions(
    name: 'support',
    trunkIds: [$inbound->getSipTrunkId()],
));
```

### Placing and transferring calls

```php
use LiveKit\Options\CreateSipParticipantOptions;

$participant = $livekit->sip->createSipParticipant(
    $outbound->getSipTrunkId(),
    '+15559876543',
    'support-call',
    new CreateSipParticipantOptions(participantIdentity: 'caller', waitUntilAnswered: true),
);

$livekit->sip->transferSipParticipant('support-call', 'caller', 'tel:+15551112222');
```

These two reach the telephone network, and they fail differently from everything else: a refusal by the
far end arrives as `SipCallError`, a `TwirpException` subclass carrying the SIP status. Catch it first —
see [Error handling](#error-handling). The WhatsApp connector below rings a real device too, but reports
failures as ordinary Twirp errors rather than as `SipCallError`.

> [!NOTE]
> `waitUntilAnswered` holds the request open while the phone rings, so the SDK raises this call's request
> timeout to the ring window plus a margin. Your HTTP client needs a socket timeout longer than that. See
> [Timeouts](#timeouts).

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

> [!WARNING]
> `whatsappCloudApiVersion` takes the version **without** the `v` that Meta's Graph path carries. The path
> is `/v23.0/{id}/calls`, but the field wants `'23.0'` — send `'v23.0'` and LiveKit answers
> `invalid_argument`, "whatsapp cloud api version not supported". The same error covers a version LiveKit
> has not allow-listed, so it does not distinguish a typo from an unsupported release: `'21.0'` is refused
> exactly like `'v23.0'` is. This package's own example got it wrong until it was checked against a live
> deployment.

An inbound call arrives on Meta's webhook with an SDP offer, which you hand to `acceptWhatsAppCall()`. The
`type` on that `SessionDescription` has to be `offer`; an `answer` is refused with `invalid_argument`,
"incorrect sdp type", before the call id is even looked at:

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

An **outbound** call is two requests, not one, and they cannot sit next to each other: `dialWhatsAppCall()`
starts the ringing, then Meta posts the callee's SDP answer to *your* webhook, and that handler completes
the handshake:

```php
use LiveKit\Options\ConnectWhatsAppCallOptions;
use LiveKit\Proto\SessionDescription;

$livekit->connector->connectWhatsAppCall(
    $callIdFromTheDial,
    new SessionDescription()->setType('answer')->setSdp($sdpFromTheWebhook),
    new ConnectWhatsAppCallOptions(waitUntilAnswered: true, timeout: 45),
);

$livekit->connector->disconnectWhatsAppCall($callIdFromTheDial, 'META_API_KEY');
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

The region list is cached for as long as its `Cache-Control: max-age` allows, and shared by the six
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
use LiveKit\Exceptions\TwirpErrorCode;
use LiveKit\Exceptions\TwirpException;

try {
    $livekit->room->deleteRoom('my-room');
} catch (TwirpException $e) {
    if ($e->getTwirpCode() === TwirpErrorCode::NOT_FOUND) {
        // ...
    }

    echo $e->getHttpStatus();  // e.g. 404
    print_r($e->getMeta());
}
```

`TwirpErrorCode` holds the eighteen codes the Twirp protocol defines, so you can match on a constant
instead of retyping a string. The code is whatever the server sent, though: if LiveKit ever adds one this
list does not know, it reaches you unchanged rather than being flattened into something else.

### Failures that did not come from LiveKit

A non-2xx response does not always come from the Twirp service. A load balancer can answer `503` with an
HTML page, a gateway can time out, an auth proxy can return `401`, and none of those is a Twirp error
envelope. Following the Twirp specification, this SDK maps such a response to the nearest code by its HTTP
status — `401` to `unauthenticated`, `404` to `bad_route`, `429` to `resource_exhausted`, `502`/`503`/`504`
to `unavailable` — and marks it, so you can tell the two apart when it matters:

```php
try {
    $livekit->room->deleteRoom('my-room');
} catch (TwirpException $e) {
    if (($e->getMeta()[TwirpErrorCode::META_FROM_INTERMEDIARY] ?? null) === 'true') {
        // Something between you and LiveKit answered: the original status is in
        // meta['status_code'], and the body it sent in meta['body'].
    }
}
```

A redirect counts as one of these. Twirp only speaks POST, so a `3xx` is never the service answering; the
`Location` it pointed at is reported in `meta['location']` rather than the body.

This is not a corner case: it is how you tell the two ways authentication fails apart. Measured against a
live LiveKit Cloud project, both arrive as `unauthenticated` with HTTP 401, and only the metadata
distinguishes them.

| what is wrong | code | metadata |
|---|---|---|
| the token is valid but carries no grant for the call | `unauthenticated` | empty — LiveKit itself answered |
| the token is expired, or signed with the wrong secret | `unauthenticated` | `http_error_from_intermediary` — the auth layer refused it before Twirp saw it |

So an empty `meta` on a 401 means your key and secret are right and the *grant* is missing, while
`http_error_from_intermediary` means the credential itself was rejected. Matching on the code alone cannot
separate those, and neither is `permission_denied` — LiveKit does not use that code for a missing grant,
so a `catch` written around it never fires.

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
    $claims = new LiveKit\TokenVerifier()->verify($jwt);
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
use LiveKit\AccessToken;
use LiveKit\Options\AccessTokenOptions;
use LiveKit\Proto\RoomConfiguration;

$token = new AccessToken('API_KEY', 'API_SECRET', new AccessTokenOptions(
    identity: 'alice',
    roomConfig: new RoomConfiguration()->setEmptyTimeout(300),
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
$rooms->method('createRoom')->willReturn(new Room()->setSid('RM_test'));

self::assertSame('RM_test', new RoomProvisioner($rooms)->provision('my-room'));
```

The interfaces declare every method its client has — nothing is available on the class but missing from
the contract, and a test fails if that ever stops being true. They are the surface to write a mock
against. Until 1.0 they can still change: the package follows semantic versioning, which permits that
before a stable major, so pin a version if a mock of yours depends on the shape.

If you would rather exercise the real client against a real transport, inject a PSR-18 double instead of
mocking the interface: the clients accept one, and it is what this package's own test suite uses. See
[Timeouts](#timeouts) for how the HTTP client is supplied.

## Runnable examples

`examples/` ships with the package. Each one runs against a real deployment, reads its credentials from
the environment, and prints what it did:

| | shows |
|---|---|
| `examples/token.php` | minting an access token with a video grant |
| `examples/room.php` | create, list, delete |
| `examples/egress.php` | recording a room to S3, then stopping it |
| `examples/ingress.php` | an RTMP endpoint, a partial update, and deleting it |
| `examples/sip.php` | an outbound trunk and a dispatch rule |
| `examples/agent-dispatch.php` | dispatching a named agent and finding it again |
| `examples/connector.php` | bridging a WhatsApp call into a room |
| `examples/webhook.php` | verifying an inbound webhook against the raw body |

Two of them could ring a telephone, and neither does so by accident. `sip.php` describes
`createSipParticipant()` without calling it, and `connector.php` prints what it would dial and exits
unless `PLACE_A_REAL_WHATSAPP_CALL=yes` is set.

## Migrating from `agence104/livekit-server-sdk`

**The two cannot be installed together.** Released versions of `agence104/livekit-server-sdk` (1.3.5 and
earlier) pin `google/protobuf` to `^3.23|^4.0`; this package needs `^5.36`, because protoc 36's generated
getters for `optional int64` fields call `GPBUtil::compatibleInt64()`, which no 4.x runtime has. Composer
refuses the pair, so this is a cutover and not a gradual move.

That is a dependency range and not a law. `^5.0` sits on that package's master branch, unreleased; the day
it ships, the paragraph below on namespaces is what decides whether the two can share a tree.

Past that, the biggest difference is namespacing — everything else maps over fairly directly.

| | `agence104/livekit-server-sdk` | `maatrics/livekit-server-sdk-php` (this package) |
|---|---|---|
| Generated protobuf classes | Global `Livekit\` namespace | `LiveKit\Proto\` (`src/Proto/`) |
| Protobuf descriptors | Bare `GPBMetadata\` root | `GPBMetadata\LiveKit\` (`metadata/`) |
| Service clients | `Agence104\LiveKit\RoomServiceClient`, etc. | `LiveKit\Services\RoomServiceClient`, etc. |
| Access tokens | `Agence104\LiveKit\AccessToken` | `LiveKit\AccessToken` |
| Webhooks | `Agence104\LiveKit\WebhookReceiver` | `LiveKit\WebhookReceiver` |

[`SipClient`](#sip) and [`AgentDispatchClient`](#agent-dispatch) are not in the table because nothing about
them is a rename — whatever the other package covers by the time you read this, these are new call sites
rather than moved ones.

`agence104/livekit-server-sdk` puts its generated protobuf classes in the **global** `Livekit\` namespace
and its descriptor metadata at the **bare `GPBMetadata\` root**. This package uses `LiveKit\Proto\` for
messages and `GPBMetadata\LiveKit\` for descriptors — the conventional root, but claimed under a prefix
of its own rather than at the top of it, as `google-cloud-php` does across its 238 metadata prefixes. No
PHP class name is claimed by both packages, so nothing in either one's autoloading would stand in the way
of a shared tree.

The runtimes would not agree about it, though, and that is worth knowing before the constraint lifts.
Both packages generate the same protobuf *messages*: `livekit.SendDataRequest` is
`livekit.SendDataRequest` in either one, whatever the PHP class is called.

**The pure-PHP runtime keys by PHP class name.** `DescriptorPool` registers each descriptor under
`$class_to_desc`, which is what encoding and decoding look up, so two sets of distinct classes do not
collide there. It also keeps a `proto_to_class` map under the protobuf full name where the last
registration wins, but that one is only consulted when resolving a message *by its protobuf name*, such
as unpacking an `Any`.

**`ext-protobuf` keys by the protobuf full name and nothing else.** The second package to call
`initOnce()` is dropped — silently, with no error from the registration itself — and every message class
in the losing package then throws `Couldn't find descriptor` for the rest of the process. Which one loses
depends only on which constructs a message first, so it is not something you can arrange for.

## Supported LiveKit protocol version

This package is generated from `livekit/protocol` **`v1.52.0`**. To regenerate against a newer tag, see
[`CONTRIBUTING.md`](CONTRIBUTING.md#regenerating-the-protobuf-classes).

## Contributing

Bug reports and pull requests are welcome — see [`CONTRIBUTING.md`](CONTRIBUTING.md) for how to run the
test suite, regenerate the protobuf classes, and what the release checklist looks like.

## Security

Found a way to mint a token that grants more than it was built from, or to get a
webhook accepted that LiveKit did not sign? Please report it privately rather than
in an issue — [SECURITY.md](SECURITY.md) says how, and what is in scope.

## License

Licensed under the [Apache License, Version 2.0](LICENSE).

The generated classes under `src/Proto/` and the descriptors under `metadata/` are derived from
[`livekit/protocol`](https://github.com/livekit/protocol), which is Apache-2.0 as well, and the
descriptors embed its `.proto` definitions verbatim. That project's attribution notice is reproduced in
[NOTICE](NOTICE), which ships inside the package: section 4(d) of the license asks anyone redistributing
this code to carry it along. This project is not affiliated with or endorsed by LiveKit, Inc.
