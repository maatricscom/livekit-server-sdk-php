# Contributing

Thanks for looking at this project. This document covers how to run the test suite, how the generated
protobuf code is maintained, and what to check before cutting a release.

## Setup

```bash
composer install
```

This installs both runtime and development dependencies, including `guzzlehttp/guzzle` and
`nyholm/psr7`, which the dev environment uses as its PSR-18/PSR-17 implementation.

PHPUnit is constrained to `^12.0 || ^13.3` rather than the newest alone, because 13 requires PHP 8.4.1
and this package supports 8.3. Composer resolves 13 on PHP 8.4 and newer and 12 on 8.3, so every PHP
version in the CI matrix gets the newest PHPUnit it can run. Both were checked against this suite,
including that `--fail-on-skipped` and the risky-test detection still exit non-zero.

## Running the test suite

There are three PHPUnit test suites, declared in `phpunit.xml.dist`:

```bash
vendor/bin/phpunit                          # unit suite (the default; no network access)
vendor/bin/phpunit --testsuite unit         # same, explicit
vendor/bin/phpunit --testsuite mock-server  # exercises livekit/test-server
vendor/bin/phpunit --testsuite integration  # exercises a real LiveKit deployment
```

The **unit suite** is the one CI runs on every push and pull request. It never makes a network call and
never needs a live LiveKit project — HTTP interactions are tested against a fake PSR-18 client. This suite
must stay green and must stay free of any requirement on external state; if you find yourself wanting to
assert something that needs a real server, it belongs in the integration suite instead.

The **mock-server suite** (`tests/MockServer/`) runs in CI against
[`livekit/test-server`](https://github.com/livekit/livekit/tree/master/cmd/test-server), the programmable
mock of the LiveKit HTTP API that every official LiveKit server SDK tests against. It is a real HTTP round
trip to a real Twirp server, and it is what proves three things a unit test cannot:

- **Our request encoding is one the server can read**, in both wire formats. This is the only coverage the
  binary `application/protobuf` path has; no official LiveKit SDK sends that content type, so nothing
  upstream would catch us getting it wrong. `EchoRoundTripTest` is the test that proves it: the mock
  copies same-named scalar fields from the *decoded* request onto its response, so a value that comes back
  is a value that survived our encoder, the wire, and the server's decoder.
- **The `VideoGrant` we mint for each RPC satisfies the server's permission table.** The mock enforces the
  same table as the real server, so `RpcSweepTest` — which calls every RPC, twice, once per wire format —
  fails with `permission_denied` on any method whose grant is too narrow. `RpcCoverageTest`, in the unit
  suite, fails if a service client grows a method that sweep does not call, so the coverage cannot quietly
  fall behind.
- **A real Twirp error envelope maps onto our exception types**, including the SIP-specific
  `sip_status_code` metadata that only a dialing failure produces.

Run it locally with Docker:

```bash
docker run --rm -p 9999-10002:9999-10002 \
  -e LK_TEST_SERVER_API_SECRET=test-server-secret-not-a-real-credential \
  livekit/test-server:latest
```

```bash
LIVEKIT_TEST_SERVER_URL=http://127.0.0.1:9999 \
LIVEKIT_TEST_SERVER_SECRET=test-server-secret-not-a-real-credential \
vendor/bin/phpunit --testsuite mock-server
```

The secret must be **at least 32 bytes** and must match the server's. `AccessToken` refuses to sign with
anything shorter, which rules out the mock's own default of `secret` (the `livekit-server --dev` value) —
so the mock has to be started with a longer one. It is not a credential; the mock accepts whatever it is
given.

Both variables are required, and each test skips itself if either is missing. CI therefore runs this suite
with `--fail-on-skipped`: without it, a job that lost its environment would report success having asserted
nothing. Keep that flag.

The **integration suite** (`tests/Integration/`) is a release gate, not a CI gate. Where the mock-server
suite proves the SDK behaves correctly against LiveKit's *model* of its API, this one proves the model is
faithful — it is the only thing that touches a real deployment, with real state, real latency and real
region behaviour. It is skipped entirely, test by test, unless all three of these environment variables
are set:

- `LIVEKIT_URL`
- `LIVEKIT_API_KEY`
- `LIVEKIT_API_SECRET`

Point them at a disposable LiveKit project (LiveKit Cloud's free tier or a local `livekit-server` both
work) and run:

```bash
LIVEKIT_URL=https://your-project.livekit.cloud \
LIVEKIT_API_KEY=... \
LIVEKIT_API_SECRET=... \
vendor/bin/phpunit --testsuite integration
```

Run this, against your own project, before every release. A green unit suite says the SDK's logic is
self-consistent; a green mock-server run says LiveKit's own mock accepts what this SDK puts on the wire;
only a green integration run says a real deployment does.

## Static analysis and style

```bash
composer analyse             # phpstan, level max, no baseline, no suppressions
vendor/bin/pint --test       # PSR-12 plus this project's rules in pint.json
composer validate --strict   # composer.json sanity
composer refactor            # rector, dry run: what a newer PHP idiom would change
```

`composer refactor` exits 2 when it has suggestions and 0 when it has none — that is Rector's contract
for `--dry-run`, not a failure. It is advisory and not a CI gate. Rector reports what it *could* rewrite, which is not
the same as what should be rewritten — it currently suggests turning classes with readonly properties
into readonly classes, including two that have no properties at all, and one whose subclass would be
dragged along with it. Read the diff before taking any of it.

Run PHPStan through the composer script rather than `vendor/bin/phpstan` directly: it carries
`--memory-limit=512M`, and PHP's 128M default is not enough for PHPStan's parallel workers on this tree.
When a worker runs out, PHPStan reports a crashed child process rather than an analysis error, which reads
like a finding but is not one.

All three must be clean. `src/Proto` is excluded from both PHPStan (`phpstan.neon.dist`) and Pint
(`pint.json`) — it is machine-generated by `protoc`, was never written to satisfy either tool, and
reformatting or re-annotating it by hand would just be undone by the next regeneration. Everything you
hand-write, including test code, is expected to pass both at their configured strictness with no
exceptions carved out.

## Regenerating the protobuf classes

`src/Proto/` is generated from a pinned tag of [`livekit/protocol`](https://github.com/livekit/protocol)
and is **committed to the repository**, not built at install time. Two reasons:

- Composer has no build step, and end users of this package must not need `protoc` installed just to
  `composer require` it.
- A CI job (`proto-drift` in `.github/workflows/ci.yml`) regenerates from the pinned tag on every push and
  fails the build if the working tree then differs from what's committed, so the checked-in output cannot
  silently drift from what the pinned tag actually produces.

To regenerate:

```bash
bin/generate-protos.sh
```

Requirements: `protoc >= 36.2` (the script checks the version and refuses to run on anything older:
its output names the canonical `RepeatedField`, gives repeated fields a generic element type, and types
every setter natively — 1153 of them, where 33.1 typed none), and bash
`>= 4` (macOS ships bash 3.2 at `/bin/bash`; `brew install bash` gets you a current one). The script clones
`livekit/protocol` at the pinned tag, computes the transitive import closure of the public Twirp-facing
protos, injects PHP namespace options so the output lands under `LiveKit\Proto\` instead of the global
`Livekit\` root the upstream protos would otherwise produce, and runs `protoc --php_out` into `src/Proto`.

After regenerating, confirm nothing changed unexpectedly:

```bash
git diff --exit-code -- src/Proto
```

An empty diff means the committed code still matches the pinned tag. A non-empty diff is expected only
when you have just bumped the pinned tag (see below) — review it like any other generated-code diff, then
commit it together with the tag bump.

### Bumping the pinned `livekit/protocol` version

1. Edit `PROTOCOL_VERSION` near the top of `bin/generate-protos.sh` to the new tag (currently `v1.52.0`).
2. Run `composer generate-protos` and review the resulting diff under `src/Proto/`. The script also
   rewrites `src/Proto/ProtocolVersion.php`, which records the tag and the exact upstream commit.
3. Update the version everywhere it is stated in prose: `README.md`, `NOTICE` and `CHANGELOG.md`, plus the
   "(currently ...)" note in step 1 above.
4. Run `composer check-protocol`. It fails if any of those files still names the old version, or if
   `src/Proto` was not regenerated. CI runs the same check, so a missed file will not reach `main`.
5. Run the full verification sweep (unit suite, phpstan, pint, and ideally the integration suite against a
   real project) to confirm the new generated code still behaves correctly.
6. Commit the diff together with the `PROTOCOL_VERSION` edit in one commit.

Do **not** rewrite the `v1.52.0` references in `src/Services/SipClient.php`,
`src/Contracts/SipClientInterface.php` or `tests/Services/SipClientTest.php`. Those state a historical
fact — `CreateSIPTrunk` was removed in that release — and stay true after a bump. `check-protocol` leaves
them alone for the same reason.

## Forbidden symbols

CI's `forbidden-symbols` job rejects any use of `\Google\Protobuf\Internal\RepeatedField` anywhere in
`src`, generated code included. That class survives only as a `class_alias`, created when
`\Google\Protobuf\RepeatedField` loads, so PSR-4 cannot autoload it and no static analyser can see it.
In hand-written code, referencing it fatals on a cold autoloader; in generated code it does not fatal,
but it costs every downstream project the types on any repeated field it touches.

protoc has emitted the canonical name since 33.1, and the generator requires 36.2 for the native
setter types on top of it.
This job is what catches a regeneration done with something older.

```bash
grep -rn 'Internal\\RepeatedField' src
```

An empty result is correct.

## Before opening a pull request

- `vendor/bin/phpunit` (unit suite) passes
- `vendor/bin/phpunit --testsuite mock-server` passes against a local `livekit/test-server`
- `composer analyse` is clean
- `vendor/bin/pint --test` is clean
- `composer validate --strict` is clean
- If you touched anything under `src/Proto` by hand: don't. Change `bin/generate-protos.sh` or bump
  `PROTOCOL_VERSION` instead, and regenerate.
