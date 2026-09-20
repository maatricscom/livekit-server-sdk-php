#!/usr/bin/env bash
#
# Fails if the pinned livekit/protocol version has drifted apart across the repo.
#
# The version is written by hand in several documents -- what the package was built
# against, the attribution for the generated code, the release record, the bump
# procedure, the design spec. Keeping those in step by remembering to edit each one
# does not survive contact with a real upgrade, so this checks it instead.
#
# The loop below is the list; this comment deliberately does not repeat it. It did
# once, and went stale the first time a file was added to the check -- which is the
# exact failure this script exists to catch.
#
# bin/generate-protos.sh is the single source of truth. src/ProtocolVersion.php
# is generated from it, so a mismatch there means the tree was not regenerated after
# the version was changed.
#
# bin/*.go pin the same tag in the `go get` line that produces the test fixtures.
# Those are reference values the PHP implementation is asserted against, so a bump
# that misses them regenerates fixtures from the OLD protocol -- and the suite stays
# green while checking the new code against stale references.
#
# Deliberately NOT checked: src/Services/SipClient.php and friends mention v1.52.0 as
# a historical fact ("the RPC was removed in that release"), which stays true after a
# bump. Rewriting those would be wrong.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

EXPECTED=$(grep -oE 'PROTOCOL_VERSION="[^"]+"' bin/generate-protos.sh | head -n1 | cut -d'"' -f2)

if [ -z "$EXPECTED" ]; then
    echo "error: could not read PROTOCOL_VERSION from bin/generate-protos.sh" >&2
    exit 1
fi

echo "Expected protocol version: ${EXPECTED}"

failed=0

# Prose that must name the current version somewhere.
#
# The design spec is included because it states the pin as a current fact --
# "pinned to a tag (currently vX)" -- and says of itself that it is kept current
# with the code, so a bump leaves it wrong in exactly the way this check exists to
# prevent. It is globbed rather than named: the filename carries a date, and a
# A missing file is reported as missing rather than as "does not mention", which is
# what a moved or renamed spec would otherwise look like.
for file in README.md NOTICE CHANGELOG.md CONTRIBUTING.md docs/design.md; do
    if [ ! -f "$file" ]; then
        echo "  FAIL ${file} is missing" >&2
        failed=1
    elif grep -qF "$EXPECTED" "$file"; then
        echo "  ok   ${file}"
    else
        echo "  FAIL ${file} does not mention ${EXPECTED}" >&2
        failed=1
    fi
done

# The `go get` line in each fixture generator, which decides which protocol the
# reference fixtures are produced from.
for file in bin/generate-jwt-fixtures.go bin/generate-webhook-fixture.go; do
    if grep -qF "livekit/protocol@${EXPECTED}" "$file"; then
        echo "  ok   ${file}"
    else
        echo "  FAIL ${file} pins a different livekit/protocol than ${EXPECTED}" >&2
        failed=1
    fi
done

# Generated code: must match exactly, not merely contain the string.
GENERATED_FILE="src/ProtocolVersion.php"

if [ ! -f "$GENERATED_FILE" ]; then
    echo "  FAIL ${GENERATED_FILE} is missing — run bin/generate-protos.sh" >&2
    failed=1
else
    # `const string TAG` since the file moved out of src/Proto and came under Pint
    # and PHPStan, which is where the typed constant comes from. The type is
    # optional in the pattern so this keeps working either way.
    ACTUAL=$(grep -oE "const (string )?TAG = '[^']+'" "$GENERATED_FILE" | head -n1 | cut -d"'" -f2)

    if [ "$ACTUAL" = "$EXPECTED" ]; then
        echo "  ok   ${GENERATED_FILE}"
    else
        echo "  FAIL ${GENERATED_FILE} says '${ACTUAL}', expected '${EXPECTED}' — regenerate" >&2
        failed=1
    fi
fi

if [ "$failed" -ne 0 ]; then
    echo >&2
    echo "The pinned protocol version is inconsistent. Update the files above, and run" >&2
    echo "bin/generate-protos.sh so src/Proto reflects the version you pinned." >&2
    exit 1
fi

echo "All references agree."
