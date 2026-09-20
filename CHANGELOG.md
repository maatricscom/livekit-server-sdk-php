# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-20

Initial release. Requires PHP 8.4 or later: 8.3 left active support at the end of 2025, and a package
starting out now has no reason to carry a version that only receives security fixes.

### Added

- `RoomServiceClient` covering all 14 RPCs of `livekit.RoomService`: creating, listing and deleting rooms;
  listing, muting, updating, removing and forwarding/moving participants; sending data; updating room
  metadata and subscriptions; and `performRpc`. `removeParticipant()` takes an optional `revokeTokenTs`,
  so a token already handed to the participant stops being accepted and cannot be used to rejoin.
- `EgressClient` covering all 10 RPCs of `livekit.Egress`, including room composite, web, participant and
  track (composite and single-track) egress, layout/stream updates, listing and stopping.
- `IngressClient` covering all 4 RPCs of `livekit.Ingress`: create, update, list and delete.
- `SipClient` covering all 16 RPCs of `livekit.SIP` plus 3 convenience wrappers: inbound/outbound trunk
  CRUD and partial field updates, dispatch rule CRUD and partial field updates, and SIP participant
  creation/transfer with `SipCallError` exposing the SIP-level status code and reason on failure.
- `AgentDispatchClient` covering all 3 RPCs of `livekit.AgentDispatchService`: create, delete and list,
  plus `getDispatch()` — `livekit.AgentDispatchService` has no GetDispatch rpc, so this is ListDispatch
  filtered by dispatch id, returning the dispatch or null rather than an array to index.
- `ConnectorClient` covering all 5 RPCs of `livekit.Connector`, bridging WhatsApp and Twilio calls into
  rooms: dial, accept, connect and disconnect a WhatsApp call, and connect a Twilio one. A LiveKit Cloud
  service — the open-source server does not implement it. `acceptWhatsAppCall()` and
  `connectWhatsAppCall()` raise the request timeout past the ring window when `waitUntilAnswered` is set,
  the same way `SipClient::createSipParticipant()` does.
- `LiveKitAPI`, a facade constructing all six service clients from one set of credentials and one
  shared HTTP client.
- An interface per service client under `LiveKit\Contracts`, so application code can depend on the
  capability rather than the concrete class and stand in for it in tests. Each declares every method its
  client has.
- Credential resolution from the environment: `LIVEKIT_URL`, and then either `LIVEKIT_TOKEN` or
  `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET`, so `new LiveKitAPI()` needs no arguments. The environment is
  read only when no credential was passed in at all — never field by field, so an explicit API key is not
  completed with a secret from the environment and an ambient token cannot stand in for credentials that
  were passed. `LIVEKIT_TOKEN` takes precedence over the key and secret, being a complete credential on
  its own.
- `AccessToken` / `AccessTokenOptions` for minting HS256 JWTs, and `TokenVerifier` for verifying and
  decoding them, with grant types `VideoGrant`, `SIPGrant`, `AgentGrant`, `InferenceGrant` and
  `ObservabilityGrant`. Tri-state permission fields (`canPublish`, `canSubscribe`, `canPublishData`,
  `canUpdateOwnMetadata`, `canSubscribeMetrics`, `canManageAgentSession`) are modeled as nullable booleans
  so that "unset" (server default applies) and "explicitly denied" cannot be conflated. A token can carry
  a `RoomConfiguration`, and `toJwt()` refuses to sign one whose egress holds storage credentials or a
  stream output — a JWT is readable by its holder, so those would be published to the participant. Track
  sources for `canPublishSources` are validated against the generated `TrackSource` enum, because the
  server maps an unrecognised one to `UNKNOWN` rather than rejecting it: an unchecked typo mints a token
  that silently grants nothing.
- Every enum-typed integer in the public API is checked against its generated protobuf enum. protoc's
  setters accept any integer, so `inputType: 99` — or a constant borrowed from the neighbouring enum —
  would otherwise be encoded and sent, and only the server would ever see that it made no sense.
- `TokenVerificationException` and a widened `WebhookVerificationException`, so that every way a token or
  a webhook can be rejected is a `LiveKitException`. Each keeps the underlying cause as its previous
  exception. The verifier pins HS256 rather than reading the algorithm from the token.
- `WebhookReceiver` for verifying LiveKit's webhook signatures against the exact raw request body and
  parsing the result into the generated `WebhookEvent` message, plus `WebhookEventType` for the known
  event names.
- Hardening across the transport: a host is required to be an absolute `http(s)` URL and rejected with a
  message saying so rather than failing later on a nonsense URI; a malformed protobuf response raises a
  `TwirpException` like a malformed JSON one, instead of letting the protobuf runtime's own exception
  escape; a non-positive `requestTimeout` sends no deadline header; a region list can never redirect a
  request to a host outside `*.livekit.cloud`; a default port in that list no longer makes a host look
  unvisited; the region-list lifetime is capped at a day; a negative ringing timeout cannot produce a
  negative request timeout; and an unparseable error body is excerpted rather than echoed whole into the
  exception message.
- `TwirpErrorCode`, the eighteen error codes the Twirp specification defines, so a caller can match on a
  constant rather than a string literal.
- Twirp-conformant handling of failures that did not come from the service: a response that is not an
  error envelope is mapped to the nearest code by its HTTP status and marked with
  `http_error_from_intermediary`, so a load balancer's HTML `503` or an auth proxy's `401` is reported as
  `unavailable` and `unauthenticated` rather than `unknown`. A `3xx` reports the `Location` it pointed at;
  Twirp only speaks POST, so a redirect is never the service answering.
- A Twirp transport (`TwirpClient`) speaking binary protobuf by default (`ClientOptions::$wireFormat` can
  switch to JSON), with `ClientOptions::$requestTimeout` driving the server-side `X-Twirp-Timeout-Ms`
  deadline.
- Automatic region failover on LiveKit Cloud (`ClientOptions::$failover`, on by default). A transport
  error or an HTTP 5xx is replayed against another region discovered from `/settings/regions`, up to three
  attempts with exponential backoff, every attempt keeping the same `X-Livekit-Request-Id` so the server
  can deduplicate. It engages only for `*.livekit.cloud` hosts, because a replay sends the caller's token
  to an origin learned at runtime. A 4xx is never replayed, and neither is a `SipCallError` — SIP status
  metadata means the callee answered, so retrying elsewhere would only dial the number again.
- Region-pin redirects. A project pinned to particular regions is turned away from any other with an HTTP
  451; the SDK rediscovers regions and sends the request to one the project is allowed. Bounded at two
  redirects per call, charged separately from the failover attempts, and — unlike failover — not disabled
  by `failover: false`, since a pinned project has no other region that would answer. No official LiveKit
  SDK implements this yet; it follows the specification in LiveKit's own SDK test server.
- Generated protobuf classes under `LiveKit\Proto\`, pinned to `livekit/protocol` **v1.52.0**. Generated with
  protoc 36.2, which the generator requires: repeated fields carry a generic `RepeatedField<T>` type and
  every setter is natively typed, so both iterating a repeated field and passing the wrong type to a
  setter are things your own static analysis can see. This is why `google/protobuf` is constrained to
  `^5.36`: protoc 36's output calls `GPBUtil::compatibleInt64`, which the 4.x runtime does not have.
  The method itself arrived in 5.34, so 5.34 is where the code stops working rather than where support
  starts — `^5.36` is the version this package is generated against and tested on. `ext-protobuf` is
  conflicted below 5.34 instead, because a conflict states what is broken, not what is supported.
  `ProtoGenerationTest` checks that every runtime helper the generated tree calls exists on the
  installed runtime, so neither number rests on a comment.
- `WebhookReceiver` rejects a body that is not a JSON object before the protobuf parser sees it, so
  what the SDK does with a malformed webhook no longer depends on which protobuf runtime is installed.
  The two disagree in opposite directions: the pure-PHP parser accepts `[1,2,3]` and returns a default
  message, `ext-protobuf` accepts an empty body and does the same. Measured by running the suite under
  both; the tests had recorded the pure-PHP answer as though it were the specification.
- A test that compared a protobuf map with an order-sensitive assertion no longer does. Maps are
  unordered, and `ext-protobuf` iterates them in hash order, so the assertion failed in 22 of 40 runs
  under the extension and never once on the pure-PHP runtime, which iterates in insertion order.
- `examples/` is analysed by PHPStan and covered by Rector, not only formatted by Pint. It was the one
  hand-written directory no tool checked, and PHPStan found a real defect there the moment it looked:
  `examples/webhook.php` passed `$_SERVER['HTTP_AUTHORIZATION']`, which is `mixed`, straight into a
  `?string` parameter. Sample code is the first thing anyone copies.
- PHPStan analyses against the whole supported PHP range (`phpVersion: min 80400, max 80599`) rather
  than against whichever PHP happens to run it. Unset, it assumed 8.5 on a machine running 8.5 and 8.4
  in CI, so a function that exists only in 8.5 — `array_first()`, say — passed locally and would have
  broken for a user on 8.4. `ToolingConfigTest` ties the lower bound to the `php` constraint in
  composer.json so the two cannot drift apart.
- CI runs the unit suite against `ext-protobuf` as well as the pure-PHP runtime, on 8.4 and 8.5. It
  previously tested only the runtime Composer installs, which is how the two items above went unnoticed.
- `ext-protobuf` is constrained to **5.34 or newer** via a `conflict` entry, not just named in `suggest`.
  The extension shadows `google/protobuf`'s classes, so the `^5.36` requirement on the Composer package
  buys nothing once the extension is loaded — and before 5.34 its `GPBUtil` has no `compatibleInt64()`,
  which protoc 36's generated getters for `optional` int64 fields call. Measured: with ext-protobuf
  4.32.1 (what Alpine ships today), `EventMetric::getEndTimestampMs()`, `ChatMessage::getEditTimestamp()`
  and `DataStream\Header::getTotalLength()` all raise `Call to undefined method`. Composer now refuses to
  install instead.
- `sendData()` puts a fresh 16-byte nonce on every packet, which is what `livekit_room.proto` asks the
  SDK to do ("added by SDK to enable de-duping of messages") and what the Node SDK does. A packet
  without one cannot be de-duplicated. `SendDataOptions::$nonce` overrides it, for the one case that
  wants it: retrying a send whose outcome is unknown, under the nonce of the send being retried.
- One naming rule across every client: the per-call option object is always `$options`, a room is `$room`
  or `$roomName` following the proto field, and `$output` / `$fields` keep their own meanings. Named
  arguments make a parameter name part of the API, so a name that changes between clients is a trap.
- `tests/MockServer/`, run in CI against `livekit/test-server` — the programmable mock of the LiveKit HTTP
  API that every official server SDK tests against. It covers every RPC in both wire formats, proving
  the grants this SDK mints satisfy the server's own permission table and that the server can decode what
  the SDK encodes. `RpcCoverageTest` fails if a service client grows a method the sweep does not call.
- `tests/Integration/`, an opt-in test suite that runs against a real LiveKit deployment when
  `LIVEKIT_URL`, `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET` are all set, to serve as a release gate.

### Notes

- `src/Proto/` carries the LiveKit signalling messages (`JoinRequest`, `Ping`, `AddTrackRequest` and the
  rest of `livekit_rtc.proto`) even though no client here calls them. They arrive through the import
  closure: `AcceptWhatsAppCall` carries a `SessionDescription`, which lives in that file. protoc cannot
  generate one message from a file, so the alternative would be dropping `acceptWhatsAppCall()`.

[0.1.0]: https://github.com/maatrics/livekit-server-sdk-php/releases/tag/v0.1.0
