# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

A LiveKit server SDK for PHP (`maatrics/livekit-server-sdk-php`), namespace `LiveKit\`, mirroring the
official Node SDK's structure. Talks to LiveKit over Twirp RPC and mints/verifies the JWTs LiveKit uses
for access tokens and webhooks.

## Commands

```bash
composer test            # phpunit, unit suite only (the default)
composer analyse         # phpstan, level max, no baseline
composer lint            # pint --test
composer fix             # pint, writes
composer refactor        # rector, dry run; advisory, exits 2 when it has suggestions
composer check-protocol  # the pinned livekit/protocol tag agrees across 8 files
composer generate-protos # regenerate src/Proto, metadata/ and src/ProtocolVersion.php
```

`vendor/bin/pint` **must never be given a path.** A path argument overrides `exclude` in `pint.json` and
reformats all 360 generated files in `src/Proto`, which the CI drift check then rejects. Use the composer
scripts, which pass none.

### The three test suites

`phpunit.xml.dist` sets `defaultTestSuite="unit"`, and the unit suite *excludes* `tests/Integration` and
`tests/MockServer`. A bare `--filter` therefore finds nothing in those two — you must name the suite:

```bash
vendor/bin/phpunit --filter SomeTest                      # unit suite
vendor/bin/phpunit --testsuite mock-server --filter X     # needs the mock server
vendor/bin/phpunit --testsuite integration --filter X     # needs a real deployment
```

- **unit** — no network. Must stay green with no external state.
- **mock-server** — `tests/MockServer/`, against `livekit/test-server`. Needs
  `LIVEKIT_TEST_SERVER_URL` and `LIVEKIT_TEST_SERVER_SECRET` (the secret must be ≥ 32 bytes;
  `AccessToken` refuses to sign with less). CI runs it with `--fail-on-skipped`, which is safe **only**
  because the missing-environment check is the suite's one and only skip. `ToolingConfigTest` fails if a
  feature-conditional skip is added there.
- **integration** — `tests/Integration/`, against a real LiveKit deployment. Needs `LIVEKIT_URL`,
  `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`. Deliberately **not** run with `--fail-on-skipped`:
  `skipIfUnavailable()` is used two dozen times because a deployment without SIP or egress is a valid one
  to run against. CI checks the environment in the shell instead, and never runs this job on a pull
  request, since a job holding these secrets would run the pull request's own code.

Integration tests run against someone's real project: everything created is deleted (see
`IntegrationTestCase::cleanUpAfter()`), and nothing may place a call, start a recording or incur a charge.
See `CONTRIBUTING.md` for how the calls that *could* reach a telephone are arranged so they cannot.

## Architecture

**Three directories, three owners.** This is the rule that explains the layout:

| | who decides when a class here changes |
|---|---|
| `src/` | us — hand-written |
| `src/Proto/` | upstream's `.proto` files, via `protoc` |
| `metadata/` | the protobuf runtime — descriptors, `GPBMetadata\LiveKit\` |

`metadata/` is outside `src/` because `composer.json` declares two PSR-4 roots — `LiveKit\ => src/` and
`GPBMetadata\LiveKit\ => metadata/`. A foreign root nested inside another's tree makes one file reachable
under two class names, which is a fatal redeclaration. `src/ProtocolVersion.php` is generated but lives in
`src/` on purpose: the script writes it, not `protoc`, so it is formatted and analysed like hand-written
code.

**`TwirpClient` is the only component that performs I/O** — the only file in `src/` that calls
`sendRequest()`. Every service client depends on it through one narrow method, which is what makes them
unit-testable against a mock PSR-18 client.

**Every client method mints its own token with its own grant.** `ServiceBase::authHeader(VideoGrant,
?SIPGrant)` is called per RPC, not per client, because LiveKit's permission table is per RPC and some of
it is counter-intuitive (`deleteRoom` needs `roomCreate`, not `roomAdmin`; the SIP call methods need
`sip.call`, not `sip.admin`). Getting one wrong produces 401s only against a strict deployment, which is
why `RpcSweepTest` exists.

**Options in, generated messages out.** `LiveKit\Options\*` are `final readonly` DTOs built with named
arguments — the one exception is `EgressBaseOptions`, an `abstract readonly` base the four egress request
shapes extend. Returns are the generated `LiveKit\Proto\*` messages, except list RPCs, which are unwrapped
to plain PHP arrays.

**A list response is unwrapped only when it holds nothing else.** Seven of the nine carry one field, so
`listRooms()` returns `Room[]` and nothing is lost. `ListEgressResponse` and `ListIngressResponse` also
carry `next_page_token`, so `listEgress()` and `listIngress()` return the message — the shape Go, Python
and Ruby return, and the reason those two are not arrays.

Each of the two then gets two more calls: `iterateEgress()` is a generator that fetches the next page only
when the caller reaches for it, and `listAllEgress()` is `iterator_to_array()` over that. The walk lives
in the generator because you can go lazy-to-eager but not back. `EgressClientTest` asserts the laziness by
queueing one page and breaking out — an eager generator asks the mock client for a response it does not
have, which is a failure rather than a quiet pass.

Generated code is **committed, not built at install time** — Composer has no build step and users must not
need `protoc`.

### Tri-state grants

Six `VideoGrant` fields are `?bool` and genuinely three-valued: `canPublish`, `canSubscribe`,
`canPublishData`, `canUpdateOwnMetadata`, `canSubscribeMetrics`, `canManageAgentSession`. `null` omits the
claim and lets the server apply its default (which **allows** publish and subscribe); `false` is an
explicit denial that must reach the wire. Any serialization that drops falsey values silently converts a
denial into a grant. This has its own test class; do not "simplify" it with `array_filter`.

## Tests that fail when you change something else

These are guards, not ordinary tests. If one goes red, it is usually telling you about a file you touched
elsewhere:

- **`RpcCoverageTest`** — every service-client method has an `RpcSweepTest` entry, and no entry names a
  method that no longer exists. Derived from `LiveKitAPI` by reflection.
- **`ProtoGenerationTest`** — fails if a file in `src/Proto/` or `metadata/` was not written by `protoc`.
- **`ReadmeCodeBlocksTest`** — parses all 33 PHP blocks in `README.md` and checks every class, named
  argument, constant and resolvable method call exists. Its `FRAGMENTS` map exempts blocks by their first
  two lines *verbatim*, so editing one of those lines means updating the key.
- **`ReadmeExamplesTest`** — runs the README's dependency-injection example and checks the interface table.
- **`ToolingConfigTest`** — ties `phpstan.neon.dist`'s `phpVersion.min` to composer's `php` constraint,
  asserts `executionOrder` is never set, and guards the mock suite's skip paths.
- **`ZzEnvLeakProbeTest`** — must sort last: it can only detect leaked environment variables from last
  place, and only PHPUnit's default alphabetical order puts it there. `ToolingConfigTest` is what fails if
  a file is added under a path that sorts after it, or if `executionOrder` is ever set.

## Skills

Three procedures in this repository are multi-step and fail expensively, so they are
packaged under `.claude/skills/` rather than left to be rediscovered:

- **`livekit-live-testing`** — before anything talks to a real deployment. Which RPCs
  may be run for real, how to reach the rest without placing a call or incurring a
  charge, and what to check afterwards.
- **`livekit-protocol-bump`** — raising the pinned tag. The order the steps go in, and
  the ones that leave the suite green while it asserts new code against stale
  references.
- **`livekit-add-rpc`** — adding a client method. Mostly about the grant, which is the
  part that is wrong silently until a user hits it.

## Regenerating the protobuf classes

`bin/generate-protos.sh` pins `PROTOCOL_VERSION` (currently `v1.52.0`) and requires protoc ≥ 36.2. It
computes the transitive import closure itself — `protoc --php_out` does not follow imports, and passing
only the service protos yields code that compiles and then dies at runtime — injects `php_namespace` and
`php_metadata_namespace` per proto package, and swaps a staging directory into place only once the whole
run has succeeded.

Bumping the tag means editing the script and running it, then `composer check-protocol`, which fails
unless every file naming the tag agrees and names the ones that do not. Do not keep a copy of that list
here or anywhere else: it is spread across prose, two Go fixture generators and generated code, and every
copy of it written down so far has gone stale. The `livekit-protocol-bump` skill has the procedure.

## Deliberate divergences from the Node SDK

Documented across `README.md` and `CHANGELOG.md`, and not bugs to fix:

- `AgentDispatchClient::getDispatch()` returns `null` for a dispatch that does not exist, whether the
  deployment reports that as an empty list or as `not_found`. Node checks only for the empty list and so
  throws in the case its own docblock documents.
- A `SipCallError` is never replayed by failover — SIP status metadata means the callee answered.
- An HTTP 451 region-pin redirect is followed; no official SDK implements this yet.
- `listEgress()` and `listIngress()` return the response message, not an array, which is what Go, Python
  and Ruby do and what Node does not — Node unwraps and drops the cursor, leaving a caller holding a page
  they cannot tell from the whole.

`docs/design.md` is the design spec: it records *why* the package is shaped this way, and is kept current
with the code rather than left as a snapshot. `docs/superpowers/` is a local working directory and is
gitignored.
