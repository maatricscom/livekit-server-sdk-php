# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-09-20

Initial release.

### Added

- `RoomServiceClient` covering all 14 RPCs of `livekit.RoomService`: creating, listing and deleting rooms;
  listing, muting, updating, removing and forwarding/moving participants; sending data; updating room
  metadata and subscriptions; and `performRpc`.
- `EgressClient` covering all 10 RPCs of `livekit.Egress`, including room composite, web, participant and
  track (composite and single-track) egress, layout/stream updates, listing and stopping.
- `IngressClient` covering all 4 RPCs of `livekit.Ingress`: create, update, list and delete.
- `SipClient` covering all 16 RPCs of `livekit.SIP` plus 3 convenience wrappers: inbound/outbound trunk
  CRUD and partial field updates, dispatch rule CRUD and partial field updates, and SIP participant
  creation/transfer with `SipCallError` exposing the SIP-level status code and reason on failure.
- `AgentDispatchClient` covering all 3 RPCs of `livekit.AgentDispatchService`: create, delete and list.
- `LiveKitClient`, a facade constructing all five service clients from one set of credentials and one
  shared HTTP client.
- `AccessToken` / `AccessTokenOptions` for minting HS256 JWTs, and `TokenVerifier` for verifying and
  decoding them, with grant types `VideoGrant`, `SIPGrant`, `AgentGrant`, `InferenceGrant` and
  `ObservabilityGrant`. Tri-state permission fields (`canPublish`, `canSubscribe`, `canPublishData`,
  `canUpdateOwnMetadata`, `canSubscribeMetrics`, `canManageAgentSession`) are modeled as nullable booleans
  so that "unset" (server default applies) and "explicitly denied" cannot be conflated.
- `WebhookReceiver` for verifying LiveKit's webhook signatures against the exact raw request body and
  parsing the result into the generated `WebhookEvent` message, plus `WebhookEventType` for the known
  event names.
- A Twirp transport (`TwirpClient`) speaking binary protobuf by default (`ClientOptions::$wireFormat` can
  switch to JSON), with `ClientOptions::$requestTimeout` driving the server-side `X-Twirp-Timeout-Ms`
  deadline.
- Generated protobuf classes under `LiveKit\Proto\`, pinned to `livekit/protocol` **v1.52.0**.
- `tests/Integration/`, an opt-in test suite that runs against a real LiveKit deployment when
  `LIVEKIT_URL`, `LIVEKIT_API_KEY` and `LIVEKIT_API_SECRET` are all set, to serve as a release gate for the
  binary-protobuf wire format.

### Not included in this release

- `ConnectorClient` (LiveKit Cloud's connector management API) is not implemented.
- Region failover against `*.livekit.cloud` is not implemented; this SDK always talks to the single host
  it is given.

Both are deferred to a later release and do not affect the five service clients, access tokens or webhooks
listed above.

[0.1.0]: https://github.com/maatrics/livekit-server-sdk-php/releases/tag/v0.1.0
