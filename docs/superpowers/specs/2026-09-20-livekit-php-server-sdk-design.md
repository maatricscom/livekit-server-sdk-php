# LiveKit PHP Server SDK — Design

> **This document records the reasoning behind the SDK's design, and is kept current with it.**
> It was written before the code existed and has since been corrected where the code moved: the
> component table in §1, the architecture in §3, the namespace mapping and protoc floor in §4, the
> transport signature in §5, the API notes in §8, the dependency table in §9, the testing section in §10,
> the tooling and CI in §11, §12, and the risk table in §13. `README.md` and `CHANGELOG.md` remain the reference for *how to use*
> the package; this file is for *why it is shaped this way*.
>
> §2 is the exception and is deliberately not updated. It records which facts were verified and on what
> machine, and rewriting that would falsify the record rather than refresh it.

**Date:** 2026-09-20
**Package:** `maatrics/livekit-server-sdk-php`
**Namespace root:** `LiveKit\`
**Status:** Implemented. This document tracks the design as built.

---

## 1. Purpose and scope

A LiveKit server SDK for PHP, written from scratch, whose structure and ergonomics mirror the official
Node.js server SDK (`livekit/node-sdks`, package `livekit-server-sdk` v2.19.0). It is published on
Packagist as an open-source library.

**In scope for v1**

| Component | Count | Notes |
|---|---|---|
| `RoomServiceClient` | 14 methods | full `livekit.RoomService` |
| `EgressClient` | 10 methods | full `livekit.Egress` |
| `IngressClient` | 4 methods | full `livekit.Ingress` |
| `SipClient` | 16 RPCs (+3 convenience wrappers) | full `livekit.SIP` minus the deleted `CreateSIPTrunk` |
| `AgentDispatchClient` | 3 RPCs (+1 convenience wrapper) | full `livekit.AgentDispatchService`; `getDispatch()` is `ListDispatch` filtered by id, since the service has no GetDispatch |
| `AccessToken` / `TokenVerifier` | — | JWT minting and verification |
| `WebhookReceiver` | — | signature + body-hash verification |
| `LiveKitAPI` facade | — | one object owning the six clients, named after Node's `LiveKitAPI` |

**Deferred to phase 2 when this was written, and since shipped**

- `ConnectorClient` (5 methods, LiveKit Cloud only)
- Region failover against `*.livekit.cloud` (`/settings/regions`, exponential backoff), with two
  deliberate divergences from the Node SDK that no official SDK has: a `SipCallError` is not replayed,
  and an HTTP 451 region-pin redirect is followed. See the "Region failover" section of `README.md`.

The reasoning for deferring them held up: both arrived as new optional constructor parameters and one new
client class. Because `ClientOptions` is built with named arguments, neither broke backward compatibility,
which is why v1 carried no placeholder fields for them — `ClientOptions` had no `failover` flag until
failover existed.

**Out of scope**

- LiveKit Cloud-only services: `CloudAgent`, `PhoneNumberService`, `AgentDBService`, `AgentSimulation`, `Replay`
- Everything under `protobufs/rpc/`, `infra/`, `roomrpc/` — these are psrpc/gRPC internals, not Twirp,
  and do not even compile without psrpc's `options.proto`, which is not vendored
- A Twirp *server* implementation. LiveKit server SDKs are clients; the only inbound surface is the webhook receiver.

## 2. Research basis

Every non-obvious decision below is backed by a fact verified during research — by fetching real source
files, by querying Packagist, or by running the toolchain locally on this machine (PHP 8.5.2, Composer 2.10.1,
protoc 29.3, Go 1.25.5, no protobuf C extension). Facts that drive the design:

1. **LiveKit publishes no official PHP SDK.** All 110 repos in the `livekit` GitHub org were enumerated;
   none is PHP. LiveKit's ecosystem table lists "PHP (community)" pointing at `agence104/livekit-server-sdk-php`.
2. **LiveKit's own Node SDK does not generate a Twirp client.** `packages/livekit-server-sdk/src/TwirpRPC.ts`
   is hand-written. This is the precedent for the transport decision in §5.
3. **`protoc --php_out` emits no service/RPC code at all** — only messages, enums and oneofs. The PHP
   Generated Code Guide has no services section. An RPC layer must come from a plugin or be hand-written.
4. **`livekit/protocol` does not use buf.** Generation is driven by `magefile.go` calling plain `protoc`.
   `buf.build/livekit/protocol` is dead: two commits, both 2022-08-21, zero tags. Protos must be pinned
   from a GitHub tag.
5. **LiveKit protos declare no `php_namespace`.** They set `go_package`, `csharp_namespace` and
   `ruby_package` only. Stock `protoc` therefore writes into the global `Livekit\`, `GPBMetadata\` and
   `Logger\` roots.
6. **Twirp is trivial on the wire:** `POST {host}/twirp/livekit.{Service}/{Method}`, body is the request
   message, errors are a JSON `{code, msg, meta}` envelope regardless of the request content type.

## 3. Architecture

```
LiveKit\                            PSR-4 root -> src/
├─ LiveKitAPI                       facade: ->room ->egress ->ingress ->sip ->agentDispatch ->connector
├─ AccessToken
├─ TokenVerifier
├─ WebhookReceiver
├─ ProtocolVersion                  GENERATED: the livekit/protocol tag src/Proto was built from
├─ Grants\
│   ├─ VideoGrant  SIPGrant  AgentGrant  InferenceGrant  ObservabilityGrant
│   ├─ ClaimGrants                  assembles the flat JWT payload
│   └─ SensitiveCredentials         refuses to sign storage credentials into a token
├─ Services\
│   ├─ ServiceBase                  auth header minting, shared transport
│   ├─ RoomServiceClient  EgressClient  IngressClient  SipClient  AgentDispatchClient
│   └─ ConnectorClient              WhatsApp and Twilio bridging, LiveKit Cloud only
├─ Contracts\                       one interface per service client, for mocking
├─ Http\
│   ├─ TwirpClient                  the only place that speaks HTTP
│   ├─ HttpClientResolver           PSR-18/PSR-17 injection with discovery fallback
│   └─ Failover  RegionCache  DialTimeout
├─ Options\                         final readonly DTOs, including AccessTokenOptions and ClientOptions
│                                   (EgressBaseOptions is the one abstract readonly base, shared by
│                                    the four egress request shapes)
├─ Enums\                           ProtoEnum  WebhookEventType  WireFormat
├─ Exceptions\
│   ├─ LiveKitException             base (interface + base class)
│   ├─ ConfigurationException       missing key/secret, empty host, short secret
│   ├─ TwirpException               code, msg, meta, httpStatus
│   ├─ TwirpErrorCode               the eighteen codes the Twirp spec defines
│   ├─ SipCallError                 extends TwirpException; sipStatusCode, sipStatus
│   ├─ TokenVerificationException
│   └─ WebhookVerificationException
└─ Proto\                           GENERATED messages, committed to the repo

GPBMetadata\LiveKit\                second PSR-4 root -> metadata/
                                    GENERATED descriptors; see §4
```

Each service client is independently constructible, exactly like the Node SDK:

```php
$rooms = new RoomServiceClient($host, $apiKey, $apiSecret);
```

The facade is a convenience, not a requirement:

```php
$livekit = new LiveKitAPI($host, $apiKey, $apiSecret);
$livekit->room->createRoom(new CreateRoomOptions(name: 'my-room'));
```

### Design boundaries

- `TwirpClient` is the only component that performs I/O. Every service client depends on it through a
  narrow method and can be unit-tested with a mock PSR-18 client, with no live server.
- `Grants\*` are pure value objects with one job: serialize to the exact JWT claim shape LiveKit's Go
  server expects. They have no knowledge of HTTP or of protobuf.
- `Options\*` are pure readonly DTOs that map to protobuf request messages. Service clients own the mapping.
- `Proto\` is generated and never hand-edited. Nothing outside the service clients imports from it except
  as return types.

## 4. Protobuf code generation

### Source of truth

`github.com/livekit/protocol`, pinned to a tag (currently **v1.52.0**), shallow-cloned by the generation
script into a temporary directory. Not vendored as a submodule, not sourced from buf.build.

### Namespace injection

Before running `protoc`, the script copies the protos into a build directory and injects PHP options per
package:

| proto package | `php_namespace` | `php_metadata_namespace` |
|---|---|---|
| `livekit` | `LiveKit\Proto` | `GPBMetadata\LiveKit` |
| `livekit.agent` | `LiveKit\Proto\Agent` | `GPBMetadata\LiveKit\Agent` |
| `logger` | `LiveKit\Proto\Logger` | `GPBMetadata\LiveKit\Logger` |

Rationale: stock output squats the **global** `Livekit\`, `GPBMetadata\` and `Logger\` roots, and collides
with `agence104/livekit-server-sdk` if both are installed — Composer resolves a doubly-claimed PSR-4 prefix
by merging the directories and using whichever it lists first, silently.

Messages move under this package's own root. Descriptors stay under `GPBMetadata`, which is where a PHP
consumer of protobuf expects them, but beneath a prefix of this package's own rather than at the bare root.
That is the distinction the collision actually turns on, and it is what large generated-protobuf PHP
codebases do: `google-cloud-php` registers 238 PSR-4 prefixes under `GPBMetadata` and not one of them is
the bare root; Temporal's PHP SDK does the same.

> As first specified, descriptors went to `LiveKit\Proto\Meta` — everything under one root, which avoided
> the collision but put them somewhere no PHP developer would look. The move to `GPBMetadata\LiveKit` came
> later, from checking what large generated-protobuf PHP codebases actually do.

### Import closure

`protoc --php_out` **does not follow imports.** Passing only the five service protos produces 194 files
that compile but die at runtime with `Class "GPBMetadata\LivekitModels" not found`, surfaced to the caller
as a misleading `{"code":"internal"}` Twirp error. The generation script therefore computes the transitive
import closure programmatically by parsing `import` statements — it does not maintain a hand-written file list.

Proto set: `livekit_room`, `livekit_models`, `livekit_metrics`, `livekit_egress`, `livekit_ingress`,
`livekit_sip`, `livekit_agent`, `livekit_agent_dispatch`, `livekit_agent_worker`, `livekit_webhook`,
`livekit_token_source`, `logger/options`, plus whatever the closure adds.

`protobufs/agent/*.proto` (package `livekit.agent`) is deliberately absent: nothing in the v1 scope imports
it — it is reached only from the out-of-scope `livekit_agent_simulation.proto` — so including it would
generate a whole namespace no SDK method ever returns.

### Flags

`--php_opt=aggregate_metadata` is **not** used. Verified during research: `aggregate_metadata=livekit`
produces a build that cannot run — `GPBMetadata\Logger\Options::initOnce()` recurses into itself roughly
559,000 frames and exhausts memory, because package `logger` does not match the `livekit` prefix. The
working form would be `aggregate_metadata=livekit#logger`, but the flag buys nothing here.

### Shipping

Generated code is committed: messages under `src/Proto/`, descriptors under `metadata/`. Composer has no
build step and end users must not need protoc. A CI job regenerates and compares — with
`git status --porcelain --untracked-files=all`, not `git diff`, because the script removes and rewrites
the output directories and `git diff` never reports an added file. So committed output cannot drift from
the pinned upstream tag.

The generation script pins a minimum protoc version (**>= 36.2**) and aborts if the local protoc is older.
protoc's reserved-word list grows between releases and can silently rename a generated class, and 36's
output is also the first to type every setter natively. CI reads the version out of the script rather than
repeating it, because a protoc that merely satisfies the minimum still generates different code and the
drift check would then report a diff nobody caused.

## 5. Transport

### Interface

```php
final readonly class TwirpClient
{
    public function request(
        string $service,      // 'RoomService'
        string $method,       // 'CreateRoom'
        Message $request,
        string $responseClass,
        string $jwt,
        ?int $timeoutSeconds = null,   // overrides ClientOptions per call
    ): Message;
}
```

### Wire format

Binary protobuf. `Content-Type: application/protobuf`; request body is `$request->serializeToString()`;
response is parsed with `mergeFromString()`.

Chosen over JSON because:
- binary preserves unknown fields silently, so the SDK does not break when LiveKit adds a proto field;
- `agence104/livekit-server-sdk` has shipped binary Twirp against LiveKit for 540k installs, which is
  production evidence that the server accepts it;
- it avoids proto3-JSON pitfalls entirely (int64-as-string, enum-as-name, `map<string,string>` key casing
  on participant attributes).

A JSON mode is available behind a `ClientOptions` flag for debugging. When JSON is used,
`mergeFromJsonString($data, true)` is called with `$ignore_unknown = true` **without exception** — the
one-argument form throws `GPBDecodeException` on any unrecognized field, reproduced during research.

### Headers

| Header | Value |
|---|---|
| `Content-Type` | `application/protobuf` (or `application/json` in JSON mode) |
| `Authorization` | `Bearer <jwt>` |
| `User-Agent` | `livekit-server-sdk-php/<version>` |
| `X-Livekit-Request-Id` | UUID v4, **stable across retries** so the server can deduplicate |

The host is normalized: a `ws://` or `wss://` prefix is rewritten to `http://` / `https://`, matching
`TwirpRPC.ts`. A trailing slash on the host must not produce a double slash in the path. Default request
timeout is 10 seconds; SIP dial methods raise it to `max(timeout, ringingTimeout + 2s)` or the request
aborts before the callee answers.

### Errors

Non-2xx responses carry a JSON `{code, msg, meta}` body even in binary mode. It is parsed with
`json_decode`, never with a protobuf message.

The `code` is passed through **verbatim**. It is not validated against a fixed enum — TwirPHP's
`ErrorCode::isValid()` lacks `malformed` and rewrites such a server error into a generic `internal`,
discarding `msg` and `meta`. `TwirpException` exposes `code`, `message`, `httpStatus` and the full `meta`
map, including `error_details` (base64 protobuf `google.rpc.Status`), which is preserved rather than decoded.

When `meta` contains `sip_status_code`, a `SipCallError` is thrown instead, exposing `sipStatusCode` and
`sipStatus`.

### HTTP client

PSR-18 client and PSR-17 factories are accepted through the constructor. When omitted, `php-http/discovery`
resolves them. Discovery failure throws `Http\Discovery\Exception\NotFoundException` — note this is *not*
`DiscoveryFailedException`, which `Psr18ClientDiscovery::find()` catches and converts; a catch on the wrong
class never fires. The SDK catches `NotFoundException` and rethrows a `ConfigurationException` naming the
packages the user can install.

No hard dependency on Guzzle. Laravel apps already ship Guzzle and Symfony apps already ship
`symfony/http-client`; discovery picks up whichever is present, so users add nothing. `composer.json` sets
`"allow-plugins": {"php-http/discovery": false}` to avoid an interactive prompt on install.

## 6. Authentication

### JWT shape

HS256, header `{"typ":"JWT","alg":"HS256"}`. The payload is **flat** — registered claims and grant keys sit
at the same level. There is no `grants` wrapper and no `jti`.

```
iss = apiKey
sub = identity
nbf = now
exp = now + ttl
iat = now
name, kind, kindDetails, metadata, attributes, sha256, roomPreset, roomConfig
video: {...}   sip: {...}   agent: {...}   inference: {...}   observability: {...}
```

### Tri-state grant fields — the highest-risk detail

Six `VideoGrant` fields are `*bool` in Go and are genuinely three-valued: `canPublish`, `canSubscribe`,
`canPublishData`, `canUpdateOwnMetadata`, `canSubscribeMetrics`, `canManageAgentSession`.

- unset → key omitted → server applies its default (`true` for `canPublish`/`canSubscribe`)
- `false` → key emitted as `false` → explicit denial

They are modelled as `?bool`. **`null` omits the key; `false` emits `false`.** A naive
`array_filter()`-style serializer drops `false` and silently converts a denial into a grant. This gets its
own dedicated test class.

Plain (non-nullable) bool grants use omitempty semantics — omitted when false. `canPublishSources`
serializes to lowercase strings: `camera`, `microphone`, `screen_share`, `screen_share_audio`. An empty
`attributes` map must not serialize as `[]` (PHP's `json_encode([])` produces an array, not an object) —
it is omitted, matching Go's omitempty.

### TTL defaults

| Token | TTL |
|---|---|
| `AccessToken` (join tokens) | 6 hours |
| Per-API-call service tokens | 10 minutes |
| Clock tolerance on verification | 60 seconds (server-side Go leeway) |

`exp` is always set — the server rejects tokens without it. `ttl` accepts seconds as `int` or a duration
string such as `'6h'`. A `roomJoin` grant without an identity throws.

### Grant per method

`ServiceBase::authHeader(VideoGrant $video, ?SIPGrant $sip = null): array` mints a fresh token per call, or
forwards a pre-signed `token` from `ClientOptions` verbatim. Signing failures propagate — they are never
swallowed into an empty header, which would surface as an opaque 401.

| Service / method | Grant |
|---|---|
| `createRoom`, `deleteRoom` | `roomCreate` |
| `listRooms` | `roomList` |
| all other RoomService methods | `roomAdmin` + `room` |
| `forwardParticipant`, `moveParticipant` | `roomAdmin` + `room` + `destinationRoom` |
| all Egress methods | `roomRecord` |
| all Ingress methods | `ingressAdmin` |
| SIP trunk / dispatch-rule methods | `sip.admin` |
| `createSipParticipant` | `sip.call` |
| `transferSipParticipant` | `roomAdmin` + `room` + `sip.call` |
| all AgentDispatch methods | `roomAdmin` + `room` |

Two of these are counter-intuitive and were confirmed against the Node source: **`deleteRoom` uses
`roomCreate`, not `roomAdmin`**, and the two SIP call methods use `sip.call`, not `sip.admin`. Getting
either wrong produces 401s against a strict deployment.

`roomAdmin` is room-scoped server-side, so the room name goes into the grant, not only into the request body.

### Secret length

`firebase/php-jwt` v7 rejects HMAC keys shorter than 32 bytes with `DomainException`. This is caught and
rethrown as a `ConfigurationException` that names the requirement, rather than surfacing as an
uncaught library exception.

## 7. Webhooks

```php
$receiver = new WebhookReceiver($apiKey, $apiSecret);
$event = $receiver->receive($rawBody, $request->getHeaderLine('Authorization'));
```

- The `Authorization` header carries the **raw JWT with no `Bearer` prefix**. An accidental `Bearer ` prefix
  is tolerated but never required.
- Verification: decode the JWT, then compare `base64_encode(hash('sha256', $rawBody, true))` against the
  token's `sha256` claim using `hash_equals`.
- The body must be the **raw request bytes**. The README documents how to obtain the raw body in plain PHP,
  Laravel and Symfony.

  Two independent reasons, both established by generating real protojson output rather than assuming:

  1. **Go's protojson is deliberately not byte-stable.** `protobuf-go` perturbs its output through an
     internal `detrand` package — the observable effect is a space after `,` and `:` that varies — and
     its own encoder tests call `detrand.Disable()` precisely so they can compare bytes. So the same
     message serialized twice need not produce identical bytes, and nothing on the receiving side may
     assume otherwise.
  2. **PHP's encoder escapes content Go emits raw:** non-ASCII becomes `\uXXXX`, `/` becomes `\/`, and a
     float loses a trailing `.0`. Real webhooks carry room names and participant metadata, so such
     content is ordinary rather than exotic.

  Note what is *not* true: a plain-ASCII payload can re-encode byte-identically in PHP, which is why a
  round-trip happening to work on one payload proves nothing about the next. The rule is to hash the
  bytes as received, always.
- Content type is `application/webhook+json`, not `application/json`. Most frameworks will not auto-parse it,
  which is desirable — it keeps the body intact.
- Parsing uses `WebhookEvent::mergeFromJsonString($rawBody, true)`; `$ignore_unknown = true` is mandatory.
- Webhook tokens have a 5-minute TTL, so clock tolerance is configurable and defaults to 60 seconds.

The receiver returns the generated `LiveKit\Proto\WebhookEvent`. It does not subclass it — the Node SDK
shadows a proto field in TypeScript, which has no safe PHP equivalent.

## 8. Public API ergonomics

The goal is that a Node developer recognizes the PHP API immediately.

```php
// Node: await roomService.createRoom({ name: 'my-room', emptyTimeout: 300 })
$room = $rooms->createRoom(new CreateRoomOptions(name: 'my-room', emptyTimeout: 300));

// Node: await roomService.listRooms()
$rooms = $rooms->listRooms();          // LiveKit\Proto\Room[]

// single-argument calls stay plain
$rooms->deleteRoom('my-room');
```

- Options are `final readonly` classes with promoted, defaulted constructors, built with named arguments.
  This gives IDE completion and static analysis where an associative array gives neither.
- List responses are unwrapped: `listRooms()` returns `Room[]`, not `ListRoomsResponse`.
- Return types are the generated protobuf messages, mirroring the Node SDK returning `@livekit/protocol` types.
- Method names are camelCase PHP conventions (`createRoom`), not the proto's PascalCase.
- SIP option-name remapping from the Node SDK is preserved: `fromNumber` → `sipNumber`, the positional
  `number` → `sipCallTo`, `participantIdentity` defaults to `sip-participant`, `playDialtone` falls back
  to `playRingtone`.
- `SipClient` implements the 16 live RPCs plus the Node convenience wrappers
  (`updateSipDispatchRuleFields`, `updateSipInboundTrunkFields`, `updateSipOutboundTrunkFields`).
  **`CreateSIPTrunk` is not implemented** — it is commented out and marked DELETED in the proto at v1.52.0,
  so a PHP method for it would 404.
- `AgentDispatchClient::getDispatch()` is this package's own convenience over `ListDispatch` filtered by
  id, since `livekit.AgentDispatchService` has no `GetDispatch` rpc. It returns `null` for a dispatch that
  does not exist whether the deployment reports that as an empty list or as a `not_found` error — a
  deliberate divergence from the Node SDK, which checks only for the empty list and therefore throws in
  the case its own docblock documents.

## 9. Dependencies

| Package | Constraint | Why |
|---|---|---|
| `ext-json` | `*` | error bodies are JSON even in binary mode, and the JSON wire format needs it |
| `php` | `^8.4` | enums, readonly classes, promotion, named args, first-class callables, `#[\Override]`, typed class constants. 8.3 left active support at the end of 2025 |
| `google/protobuf` | `^5.36` | the version this package is generated against and tested on. protoc 36's getters for `optional` int64 fields call `GPBUtil::compatibleInt64()`, which no 4.x runtime has |
| `firebase/php-jwt` | `^7.1` | zero runtime dependencies, no ext-sodium; `lcobucci/jwt` pins exact PHP minors |
| `psr/http-client`, `psr/http-factory`, `psr/http-message` | `^1.0.3`, `^1.1`, `^2.0` | transport interfaces |
| `php-http/discovery` | `^1.20` | optional client resolution |
No UUID package is required. `X-Livekit-Request-Id` needs a UUID v4 only as an opaque identifier, which is
a few lines over `random_bytes(16)` — not worth a dependency in a library that other packages will install.

`ext-protobuf` is **suggested, not required** — the pure-PHP runtime was verified to work end-to-end with
no C extension. `ext-bcmath` is suggested for JSON deserialization in the pure-PHP path.

`composer.json` also declares `conflict: {"ext-protobuf": "<5.34"}`. The extension shadows
`google/protobuf`'s classes, so the requirement above stops applying the moment it is loaded, and before
5.34 its `GPBUtil` has no `compatibleInt64()`. A conflict states what is broken; the require floor states
what is supported, which is why the two numbers differ.

**Compatibility trap:** `Google\Protobuf\Internal\RepeatedField` moved in v5 and survives only as a
`class_alias`, which PSR-4 cannot autoload — referencing it fatals on a cold autoloader. All SDK code uses
`\Google\Protobuf\RepeatedField`. `MapField` did *not* move and stays `Google\Protobuf\Internal\MapField`.
Because the declared constraint straddles this break, CI tests both ends of the range.

No dependency on `twirp/twirp`: the transport is hand-written, so the runtime's `Error`, `ErrorCode` and
`Context` types are not needed, and the package's 1.0 line is still a release candidate.

## 10. Testing

The single biggest weakness of the existing PHP SDK is that its tests require a live LiveKit server and an
RTMP tunnel, so nothing runs in ordinary CI. This SDK is fully unit-testable.

- **Transport:** mock PSR-18 client. Assert URL, method, headers, content type, body bytes, request-id
  stability across retries, host normalization, timeout propagation.
- **Errors:** synthetic `{code, msg, meta}` bodies covering unknown codes (`malformed`), SIP metadata,
  `error_details` preservation, and non-JSON error bodies.
- **Grants:** a dedicated test class for tri-state serialization — `null` omits, `false` emits, `true`
  emits — plus `canPublishSources` strings and empty-`attributes` handling.
- **AccessToken:** golden tests against JWTs produced by the Go implementation, asserting the flat claim
  layout, absence of `jti`, and TTL defaults.
- **WebhookReceiver:** fixture body + matching signature; tampered body; wrong key; expired token;
  `Bearer`-prefixed header; clock tolerance.
- **Service clients:** each method asserts the correct Twirp path, the correct grant in the minted token,
  and correct option-to-proto mapping.
- **Against `livekit/test-server`:** a second suite runs in CI against LiveKit's own programmable mock —
  the one every official server SDK tests against. It is what proves the request encoding is one the
  server can read, in both wire formats, and that the grant minted for each RPC satisfies the server's
  permission table. A unit test with a fake HTTP client cannot prove either.
- **Integration tests** against a real LiveKit server are gated behind environment variables, so they skip
  themselves entirely for anyone without a deployment to point them at. CI runs them as their own job from
  repository secrets, on pushes to `main` and on manual dispatch but never on a pull request: a job holding
  a live API key that runs the code in an arbitrary pull request is a way to publish that key. The job
  checks the secrets are present rather than passing `--fail-on-skipped`, because a skip is also how a
  deployment without SIP or egress reports a feature it does not have, and the two must not be conflated. Sixty of them now exist, covering room lifecycle, ingress on both push
  input types, SIP trunk and dispatch-rule configuration, agent dispatch, all five connector RPCs, the
  read-only RPCs, the shape of a server error, the grants a minted token actually buys against a live
  deployment, and a sweep that drives every remaining room, egress and agent-dispatch method; they have been run green against a live LiveKit Cloud project
  in both wire formats. The sweep asserts the Twirp code a real deployment answers with, because for a
  method a server SDK cannot reach a live object for -- muting a track, starting an egress -- that code
  is the proof the route, the encoding and the minted grant are all right: a wrong one would fail as
  `bad_route`, `malformed` or `permission_denied` instead. Anything they create is deleted even when an assertion
  fails, and a deployment without SIP or egress provisioned skips rather than fails — a suite that goes
  red on a valid deployment teaches people to ignore it.

## 11. Tooling and CI

- **PHPUnit** `^13.3`, failing on warnings, risky tests, deprecations, notices and an empty test suite.
  `executionOrder` is deliberately unset: the environment-leak probe can only run last under the default
  alphabetical order.
- **PHPStan** at max level, pinned to the supported PHP range (`phpVersion: min 80400, max 80599`) rather
  than to whichever PHP runs it. `src/Proto` is in `excludePaths.analyse` — not `analyseAndScan`, so
  generated classes still resolve when checking hand-written code. `examples/` is analysed too.
- **Laravel Pint** for formatting; `src/Proto` and `metadata/` excluded (files carry a DO NOT EDIT banner)
- **Rector**, advisory rather than a gate, with `src/Proto` skipped. Currently clean:
  `ReadOnlyClassRector` and `NewMethodCallWithoutParenthesesRector` have been applied across the
  hand-written tree, so `composer refactor` exits 0
- **GitHub Actions:** matrix PHP 8.4 / 8.5 × `prefer-lowest` / `prefer-stable`, `fail-fast: false`.
  The `prefer-lowest` leg is what catches a too-loose constraint. Separate jobs for PHPStan, Pint
  `--test`, `composer validate --strict`, ShellCheck over `bin/*.sh`, `gofmt` over the fixture generators,
  the pinned-protocol-version check, the forbidden-symbol check, the proto drift check, and the
  integration suite against a real deployment. Twelve in all.
- **A second runtime:** one job runs the unit suite against `ext-protobuf`. The pure-PHP runtime and the
  extension do not agree on every edge case — each accepts malformed input the other rejects — so testing
  only the one Composer installs leaves half the installed base unexercised.
- **PSR-18 matrix:** the suite runs against both `guzzlehttp/guzzle` and `symfony/http-client` as a
  dev-dependency axis, since discovery behaviour differs per implementation.
- `.gitattributes` with `export-ignore` for `/tests`, `/.github`, `/bin`, `/docs`, config files, keeping
  the dist tarball to `src/`, `metadata/`, `examples/` and the five documents a consumer might read: README, CHANGELOG, SECURITY, LICENSE and NOTICE. `metadata/` is not
  export-ignored: the package does not load without it.
- `declare(strict_types=1)` everywhere; PSR-12.

## 12. Documentation

- `README.md`: installation, quickstart per service, AccessToken examples, webhook setup for plain PHP,
  Laravel and Symfony, and an explicit note on obtaining the raw request body.
- A migration note for users coming from `agence104/livekit-server-sdk`, since class names differ
  (`LiveKit\Proto\Room` vs `Livekit\Room`).
- `CONTRIBUTING.md` covering proto regeneration.
- The pinned `livekit/protocol` version is stated by hand in the README, NOTICE, CHANGELOG and
  CONTRIBUTING, in the `go get` line of each fixture generator, and generated into
  `src/ProtocolVersion.php`. `bin/check-protocol-version.sh` treats the generation script as the
  source of truth and fails if any of them has been left behind.

## 13. Risks

| Risk | Mitigation |
|---|---|
| Tri-state grant serialization silently grants permission | Dedicated test class; `?bool` types; never `array_filter` |
| Missing transitive proto import → misleading runtime `internal` error | Import closure computed programmatically; CI instantiates a message rather than only checking protoc's exit code |
| LiveKit adds a proto field | Binary transport preserves unknown fields; JSON mode always passes `ignore_unknown = true` |
| `google/protobuf` v4/v5 behavioural break (`RepeatedField`) | Never reference `Internal\RepeatedField`; CI tests both ends of the constraint |
| Upstream proto drift | CI regenerates and fails on `git diff` |
| Binary content type less exercised than JSON on LiveKit Cloud | Closed: the opt-in suite passes against a live LiveKit Cloud project and exercises both wire formats; JSON mode is one flag away |
| Namespace choice diverges from the community SDK | Documented migration note; the tradeoff was accepted deliberately to avoid global namespace squatting |
