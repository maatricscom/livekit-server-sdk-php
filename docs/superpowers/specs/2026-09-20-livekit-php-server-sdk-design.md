# LiveKit PHP Server SDK — Design

**Date:** 2026-09-20
**Package:** `maatrics/livekit-server-sdk`
**Namespace root:** `LiveKit\`
**Status:** Approved design, ready for implementation planning

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
| `AgentDispatchClient` | 3 methods | full `livekit.AgentDispatchService` |
| `AccessToken` / `TokenVerifier` | — | JWT minting and verification |
| `WebhookReceiver` | — | signature + body-hash verification |
| `LiveKitClient` facade | — | equivalent of Node's `LiveKitAPI` |

**Deferred to phase 2**

- `ConnectorClient` (5 methods, LiveKit Cloud only)
- Region failover against `*.livekit.cloud` (`/settings/regions`, exponential backoff)

Phase-2 work adds new optional constructor parameters and a new client class. Because `ClientOptions` is
built with named arguments, neither breaks backward compatibility, so v1 carries no placeholder fields for
them — `ClientOptions` has no `failover` flag until failover actually exists.

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
LiveKit\                            single PSR-4 root -> src/
├─ LiveKitClient                    facade: ->room ->egress ->ingress ->sip ->agentDispatch
├─ AccessToken
├─ AccessTokenOptions
├─ TokenVerifier
├─ WebhookReceiver
├─ ClientOptions                    requestTimeout, prefix, presigned token, wire format
├─ Grants\
│   ├─ VideoGrant  SIPGrant  AgentGrant  InferenceGrant  ObservabilityGrant
│   └─ ClaimGrants                  assembles the flat JWT payload
├─ Services\
│   ├─ ServiceBase                  auth header minting, shared transport
│   ├─ RoomServiceClient  EgressClient  IngressClient  SipClient  AgentDispatchClient
├─ Contracts\                       one interface per service client, for mocking
├─ Http\
│   ├─ TwirpClient                  the only place that speaks HTTP
│   └─ HttpClientResolver           PSR-18/PSR-17 injection with discovery fallback
├─ Options\                         final readonly DTOs (CreateRoomOptions, SendDataOptions, ...)
├─ Enums\
├─ Exceptions\
│   ├─ LiveKitException             base (interface + base class)
│   ├─ ConfigurationException       missing key/secret, empty host, short secret
│   ├─ TwirpException               code, msg, meta, httpStatus
│   ├─ SipCallError                 extends TwirpException; sipStatusCode, sipStatus
│   └─ WebhookVerificationException
└─ Proto\                           GENERATED, committed to the repo
```

Each service client is independently constructible, exactly like the Node SDK:

```php
$rooms = new RoomServiceClient($host, $apiKey, $apiSecret);
```

The facade is a convenience, not a requirement:

```php
$livekit = new LiveKitClient($host, $apiKey, $apiSecret);
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
| `livekit` | `LiveKit\Proto` | `LiveKit\Proto\Meta` |
| `livekit.agent` | `LiveKit\Proto\Agent` | `LiveKit\Proto\Meta\Agent` |
| `logger` | `LiveKit\Proto\Logger` | `LiveKit\Proto\Meta\Logger` |

Rationale: stock output squats the global `GPBMetadata\` and `Logger\` roots and collides with
`agence104/livekit-server-sdk` if both are installed. Injection puts all generated code under the single
`LiveKit\` PSR-4 root. Verified working during research (285 files, one root).

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

Generated code is committed under `src/Proto/`. Composer has no build step and end users must not need
protoc. A CI job regenerates and asserts `git diff --exit-code`, so committed output cannot drift from
the pinned upstream tag. The generation script pins a minimum protoc version (>= 29.3, the version verified
during research) and aborts if the local protoc is older, because protoc's reserved-word list grows between
releases and can silently rename a generated class — protoc 29.3 has 80 reserved words, current main has 83.

## 5. Transport

### Interface

```php
final class TwirpClient
{
    public function request(
        string $service,      // 'RoomService'
        string $method,       // 'CreateRoom'
        Message $request,
        string $responseClass,
        string $authToken,
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
- The body must be the **raw request bytes**. Re-serializing the JSON breaks verification, because Go's
  protojson byte layout is not reproducible. The README documents how to obtain the raw body in plain PHP,
  Laravel and Symfony.
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

## 9. Dependencies

| Package | Constraint | Why |
|---|---|---|
| `php` | `^8.3` | enums, readonly classes, promotion, named args, first-class callables, `#[\Override]`, typed class constants |
| `google/protobuf` | `^4.33.6 \|\| ^5.36` | lower bound excludes CVE-2026-6409 (DoS via negative varints); Composer 2.10 already refuses affected versions |
| `firebase/php-jwt` | `^7.1` | zero runtime dependencies, no ext-sodium; `lcobucci/jwt` pins exact PHP minors |
| `psr/http-client`, `psr/http-factory`, `psr/http-message` | `^1.0.3`, `^1.1`, `^2.0` | transport interfaces |
| `php-http/discovery` | `^1.20` | optional client resolution |
No UUID package is required. `X-Livekit-Request-Id` needs a UUID v4 only as an opaque identifier, which is
a few lines over `random_bytes(16)` — not worth a dependency in a library that other packages will install.

`ext-protobuf` is **suggested, not required** — the pure-PHP runtime was verified to work end-to-end with
no C extension. `ext-bcmath` is suggested for JSON deserialization in the pure-PHP path.

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
- **Integration tests** against a real LiveKit server are opt-in, gated behind environment variables, and
  never required for CI to pass.

## 11. Tooling and CI

- **PHPUnit** `^12`
- **PHPStan** at max level. `src/Proto` is in `excludePaths.analyse` — not `analyseAndScan`, so generated
  classes still resolve when checking hand-written code.
- **Laravel Pint** for formatting; `src/Proto` excluded (files carry a DO NOT EDIT banner)
- **Rector**, with `src/Proto` skipped
- **GitHub Actions:** matrix PHP 8.3 / 8.4 / 8.5 × `prefer-lowest` / `prefer-stable`, `fail-fast: false`.
  The `prefer-lowest` leg is what catches a too-loose constraint. Separate jobs for PHPStan, Pint
  `--test`, `composer validate --strict`, and the proto drift check.
- **PSR-18 matrix:** the suite runs against both `guzzlehttp/guzzle` and `symfony/http-client` as a
  dev-dependency axis, since discovery behaviour differs per implementation.
- `.gitattributes` with `export-ignore` for `/tests`, `/.github`, `/bin`, config files, keeping the dist
  tarball small while still shipping `src/Proto`.
- `declare(strict_types=1)` everywhere; PSR-12.

## 12. Documentation

- `README.md`: installation, quickstart per service, AccessToken examples, webhook setup for plain PHP,
  Laravel and Symfony, and an explicit note on obtaining the raw request body.
- A migration note for users coming from `agence104/livekit-server-sdk`, since class names differ
  (`LiveKit\Proto\Room` vs `Livekit\Room`).
- `CONTRIBUTING.md` covering proto regeneration.
- The pinned `livekit/protocol` version is stated in the README and in the generation script.

## 13. Risks

| Risk | Mitigation |
|---|---|
| Tri-state grant serialization silently grants permission | Dedicated test class; `?bool` types; never `array_filter` |
| Missing transitive proto import → misleading runtime `internal` error | Import closure computed programmatically; CI instantiates a message rather than only checking protoc's exit code |
| LiveKit adds a proto field | Binary transport preserves unknown fields; JSON mode always passes `ignore_unknown = true` |
| `google/protobuf` v4/v5 behavioural break (`RepeatedField`) | Never reference `Internal\RepeatedField`; CI tests both ends of the constraint |
| Upstream proto drift | CI regenerates and fails on `git diff` |
| Binary content type less exercised than JSON on LiveKit Cloud | Verify against a real deployment before release; JSON mode is one flag away |
| Namespace choice diverges from the community SDK | Documented migration note; the tradeoff was accepted deliberately to avoid global namespace squatting |
