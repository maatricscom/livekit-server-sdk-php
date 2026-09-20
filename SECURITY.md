# Security policy

## Reporting a vulnerability

**Please do not open a public issue for a security problem.** A public report is
readable by everyone, including people running the affected version, before there
is a fix for them to move to.

Report it privately through GitHub instead, from the **Security** tab of this
repository — *Report a vulnerability*. That opens a draft advisory only the
maintainers can see, and it is the preferred route because the discussion, the
fix and the published advisory all stay attached to one another.

If you cannot use that, email **security@maatrics.com**.

Please include enough to reproduce it: the package version, the PHP version, the
protobuf runtime (the pure-PHP one or `ext-protobuf`), and the smallest snippet
that shows the problem. If you have a patch, attach it to the advisory rather
than opening a pull request, for the same reason as above.

You will get an acknowledgement within a few working days. We will tell you what
we found, whether we agree it is a vulnerability, and when a fix is likely. You
are credited in the advisory unless you ask not to be.

## What is in scope

This package mints and verifies the JWTs that authenticate against a LiveKit
deployment, and verifies the signatures on LiveKit's webhooks. Anything that
weakens either is in scope, and the cases worth naming because they are easy to
get subtly wrong:

- A token that grants more than the `VideoGrant` it was built from — particularly
  a tri-state permission (`canPublish`, `canSubscribe`, `canPublishData`,
  `canUpdateOwnMetadata`, `canSubscribeMetrics`, `canManageAgentSession`) where an
  explicit `false` fails to reach the wire and the server applies its permissive
  default instead.
- A webhook this package accepts that LiveKit did not sign, or one whose body was
  altered after signing.
- A signature, hash or token comparison that is not constant-time.
- An API secret, access token or storage credential that escapes into an
  exception message, a log line, or a token payload. `AccessToken::toJwt()`
  refusing to sign a `RoomConfiguration` that carries storage credentials is
  defensive code of exactly this kind; a way around it is a vulnerability.
- A host or region a request can be redirected to that is not the one configured.
  Failover is restricted to `*.livekit.cloud` precisely because a replay sends
  the caller's token to an origin learned at runtime.

## What is not in scope

- Vulnerabilities in LiveKit's server, which belong to
  [`livekit/livekit`](https://github.com/livekit/livekit/security).
- Anything requiring an attacker who already holds your API secret. That
  credential authenticates every call this package makes; a leak of it is an
  incident for your deployment, not a flaw in this code.
- The generated classes under `src/Proto/` and `metadata/`, which are produced by
  `protoc` from a pinned tag of `livekit/protocol`. A problem there is upstream's;
  say so in the report and we will forward it.
- Anything reachable only by passing attacker-controlled input as an API key,
  secret or host — those are configuration, not user input.

## Supported versions

Until 1.0, fixes go onto the latest released minor. The version of
`livekit/protocol` the package is generated against is stated in `README.md` and
in `LiveKit\ProtocolVersion`.
