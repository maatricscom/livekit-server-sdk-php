#!/usr/bin/env bash
#
# Fails if the pinned livekit/protocol version has drifted apart across the repo.
#
# The version is written by hand in several places — the README tells users what
# the package was built against, the NOTICE attributes the generated code, the
# CHANGELOG records it per release, and CONTRIBUTING documents the bump. Keeping
# those in step by remembering to edit each one does not survive contact with a
# real upgrade, so this checks it instead.
#
# bin/generate-protos.sh is the single source of truth. src/Proto/ProtocolVersion.php
# is generated from it, so a mismatch there means the tree was not regenerated after
# the version was changed.
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
for file in README.md NOTICE CHANGELOG.md CONTRIBUTING.md; do
    if grep -qF "$EXPECTED" "$file"; then
        echo "  ok   ${file}"
    else
        echo "  FAIL ${file} does not mention ${EXPECTED}" >&2
        failed=1
    fi
done

# Generated code: must match exactly, not merely contain the string.
GENERATED_FILE="src/Proto/ProtocolVersion.php"

if [ ! -f "$GENERATED_FILE" ]; then
    echo "  FAIL ${GENERATED_FILE} is missing — run bin/generate-protos.sh" >&2
    failed=1
else
    ACTUAL=$(grep -oE "const TAG = '[^']+'" "$GENERATED_FILE" | head -n1 | cut -d"'" -f2)

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
