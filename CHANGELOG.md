# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-20

Initial release.

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
- Generated protobuf classes under `LiveKit\Proto\`, pinned to `livekit/protocol` **v1.52.0**.
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
