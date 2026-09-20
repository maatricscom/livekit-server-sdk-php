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
protobuf runtime (the pure-PHP one or `ext-protobuf`), the `livekit/protocol` tag
the package was generated against — `LiveKit\ProtocolVersion::TAG` reports it —
and the smallest snippet that shows the problem. If you have a patch, attach it to the advisory rather
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
- A token `TokenVerifier` accepts that it should refuse: one signed with a
  different secret, one outside its own `nbf`/`exp` window beyond the configured
  clock tolerance, one issued for a different API key, or one that gets a
  different algorithm honoured. HS256 is pinned rather than read from the token's
  own header, so `alg: none` and a same-secret HS384 or HS512 are both refused —
  anything that gets past that is in scope.
- A webhook this package accepts that LiveKit did not sign, or one whose body was
  altered after signing.
- A signature, hash or token comparison that is not constant-time.
- An API secret, access token or storage credential that escapes into an
  exception message, a log line, or a token payload. `AccessToken::toJwt()`
  refuses by default to sign a `RoomConfiguration` carrying storage credentials,
  since a JWT is readable by whoever holds it. Getting such a token signed
  *without* calling `AccessToken::allowSensitiveCredentials()` is a vulnerability.
  Calling it is not: it is a documented opt-out for a token that stays
  server-side, and is working as intended.
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

Until 1.0, fixes go onto the latest released minor only. There is no backport to
earlier ones, so upgrading is how you get a fix; the versioning is semantic, and
before a stable major that permits a breaking change in a minor.
