---
name: livekit-protocol-bump
description: Use when raising the pinned livekit/protocol tag, regenerating src/Proto and metadata/, or when composer check-protocol fails. Covers the order the steps have to happen in, the protoc requirement, and the failures that stay green if a step is skipped.
---

# Bumping the pinned livekit/protocol tag

The tag is named in several files and `bin/check-protocol-version.sh` fails unless
they all agree. That script is the authority on which they are — run it rather than
working from a list, because such a list goes stale exactly the way a count of it
would. (This paragraph used to give the count. It was wrong within a day.)

`bin/generate-protos.sh` is the source of truth for the version itself. Everything
else either quotes it or is generated from it.

## Order matters

**1. Edit `PROTOCOL_VERSION` in `bin/generate-protos.sh`.** Nothing else sets it.

**2. Update the `go get` line in both `bin/*.go`.** These produce the reference JWTs
and the webhook body the unit suite asserts against. Skip this and the fixtures are
regenerated from the *old* protocol while the code is new — the suite stays green
asserting new behaviour against stale references, which is the worst outcome
available here. `check-protocol` covers it, so do not rely on noticing.

**3. Regenerate the fixtures**, following the `go run` lines in each generator's
header comment.

**4. Run `composer generate-protos`.** It needs protoc **≥ the floor the script
declares**, and refuses to run otherwise — that refusal is correct, not an obstacle:
protoc's output differs between versions, and CI's drift job compares against
committed files.

If you need protoc, download the release binary. If you need `livekit/test-server`,
note that `go install github.com/livekit/livekit/cmd/test-server@<tag>` **cannot
work** — that module's `go.mod` carries `replace` directives and Go refuses to install
a package whose module would be read differently as a dependency than as the main
module. Clone it and `go build ./cmd/test-server` instead. Expect several hundred
megabytes of Go modules; ask first if bandwidth is metered.

**5. Update the prose.** `composer check-protocol` names every file that disagrees.
Do not edit anything it does not name: mentions of a version as a *historical fact*
("the RPC was removed in that release") stay true after a bump, which is why
`src/Services/SipClient.php` is deliberately not checked.

**6. Run everything.**

```bash
composer test && composer analyse && composer lint && composer check-protocol
```

## What a bump surfaces that nothing else does

`protoc` emits no service code, so a new RPC upstream leaves no trace in `src/Proto`.
Nothing in the repository notices it. If upstream added one you want, adding it is a
separate job — see the `livekit-add-rpc` skill.

Two guards fire on a bad regeneration, and both are telling you something real:

- **`ProtoGenerationTest`** — a file in a generated tree was not written by `protoc`.
  Something hand-written got in, and the next generation run will delete it.
- **the forbidden-symbol check** — `Internal\RepeatedField` appeared. It survives only
  as a `class_alias`, which PSR-4 cannot autoload, and it means the tree was
  regenerated with a protoc older than the floor.

## Verifying the result

Zero drift is the whole test, and it is the same command CI runs:

```bash
git status --porcelain --untracked-files=all -- src/Proto metadata src/ProtocolVersion.php
```

Empty means the committed tree matches the pinned tag byte for byte. A regeneration
that produces no diff at all is the expected outcome when re-running at the same tag,
and is worth doing once after any change to the generator itself.

Expect `git` to print `refs/tags/vX ... is not a commit!` during the clone. LiveKit's
release tags are annotated and a `--depth 1` pack cannot follow the tag object to the
commit while writing the ref. The checkout is still correct; `PROTOCOL_COMMIT` is read
from the working tree, not from the ref.
