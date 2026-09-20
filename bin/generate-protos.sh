#!/usr/bin/env bash
#
# Regenerates src/Proto, metadata/ and src/ProtocolVersion.php from a pinned
# livekit/protocol tag.
#
# Two non-obvious things this script handles, both of which produce broken
# builds if you do them by hand:
#
#   1. `protoc --php_out` does NOT follow imports. Passing only the service
#      protos yields code that compiles but dies at runtime with
#      `Class "GPBMetadata\LivekitModels" not found`, surfaced to callers as a
#      misleading Twirp `{"code":"internal"}`. We compute the transitive import
#      closure instead of maintaining a file list by hand.
#
#   2. LiveKit's protos declare no php_namespace, so stock protoc squats the
#      global `Livekit\`, `GPBMetadata\` and `Logger\` roots. We inject PHP
#      namespace options into a build copy so everything lands under `LiveKit\`.
#
# We deliberately do NOT pass --php_opt=aggregate_metadata. With only the
# `livekit` prefix it produces a build whose GPBMetadata\Logger\Options::initOnce()
# recurses into itself until memory is exhausted.

set -euo pipefail

if ((BASH_VERSINFO[0] < 4)); then
    echo "error: bash >= 4 required (found ${BASH_VERSION}); the import-closure pass needs associative arrays." >&2
    echo "       macOS ships bash 3.2 at /bin/bash — install a newer one with: brew install bash" >&2
    exit 1
fi

PROTOCOL_VERSION="v1.52.0"
MIN_PROTOC_VERSION="36.2"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${REPO_ROOT}/build/protos"
OUT_DIR="${REPO_ROOT}/src/Proto"
# Descriptor metadata lands outside src/, under the conventional GPBMetadata root.
# See the namespace mapping below for why it is not src/Proto/Meta.
META_OUT_DIR="${REPO_ROOT}/metadata"
# Written by this script, but hand-shaped, so it sits with the hand-written code.
VERSION_FILE="${REPO_ROOT}/src/ProtocolVersion.php"

if ! command -v protoc >/dev/null 2>&1; then
    echo "error: protoc not found. Install protobuf >= ${MIN_PROTOC_VERSION}." >&2
    exit 1
fi

protoc_version="$(protoc --version | awk '{print $2}')"
if [ "$(printf '%s\n%s\n' "$MIN_PROTOC_VERSION" "$protoc_version" | sort -V | head -n1)" != "$MIN_PROTOC_VERSION" ]; then
    echo "error: protoc ${protoc_version} is older than the required ${MIN_PROTOC_VERSION}." >&2
    echo "       protoc's reserved-word list grows between releases and can silently rename generated classes." >&2
    exit 1
fi

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

echo "==> Cloning livekit/protocol ${PROTOCOL_VERSION}"
git clone --quiet --depth 1 --branch "$PROTOCOL_VERSION" \
    https://github.com/livekit/protocol.git "${BUILD_DIR}/protocol"

SRC="${BUILD_DIR}/protocol/protobufs"
WORK="${BUILD_DIR}/work"
mkdir -p "$WORK"

# Public, Twirp-facing protos only. Excluded on purpose:
#   rpc/*, infra/, roomrpc/   -> psrpc & gRPC internals; they do not even compile
#                                without psrpc's options.proto, which is not vendored
#   livekit_internal, livekit_analytics       -> server internals
#
# livekit_rtc.proto is NOT a root but arrives through the closure anyway:
# livekit_connector_whatsapp.proto imports it for SessionDescription, which
# AcceptWhatsAppCall carries. That pulls in the signalling messages (JoinRequest,
# Ping, AddTrackRequest and friends) -- 68 files and about 293K of generated code
# this SDK never calls. Count it with:
#     grep -rl 'source: livekit_rtc.proto' src/Proto metadata | xargs wc -c | tail -1 There is no way to generate one message from a file, and dropping
# AcceptWhatsAppCall to avoid it would leave the Connector client incomplete, so
# the size is accepted deliberately rather than by oversight.
ROOTS=(
    livekit_room.proto
    livekit_models.proto
    livekit_metrics.proto
    livekit_egress.proto
    livekit_ingress.proto
    livekit_sip.proto
    livekit_agent.proto
    livekit_agent_dispatch.proto
    livekit_agent_worker.proto
    livekit_webhook.proto
    livekit_token_source.proto
    # Pulls in livekit_connector_whatsapp.proto and livekit_connector_twilio.proto
    # through the import closure below.
    livekit_connector.proto
)

echo "==> Resolving transitive import closure"
declare -A SEEN=()
QUEUE=("${ROOTS[@]}")

# A read index rather than reslicing the array. Dropping the head with
# QUEUE=("${QUEUE[@]:1}") expands to nothing on the last element, which bash
# before 4.4 treats as an unbound variable under `set -u` -- so the old form
# needed 4.4 while the gate above only asks for 4.0, the floor `declare -A`
# actually sets. Advancing an index needs neither, and rebuilds no array.
#
# `head=$((head + 1))` rather than `((head++))`: the latter evaluates to the
# value *before* the increment, so on the first pass it yields 0, which is a
# false arithmetic result and therefore exit status 1 -- which `set -e` acts on.
# It would kill the script on its first proto.
head=0
while ((head < ${#QUEUE[@]})); do
    current="${QUEUE[head]}"
    head=$((head + 1))

    [ -n "${SEEN[$current]:-}" ] && continue
    # google/protobuf/*.proto ships with protoc itself; never copy or compile it
    case "$current" in google/protobuf/*) continue ;; esac
    [ -f "${SRC}/${current}" ] || { echo "error: missing proto ${current}" >&2; exit 1; }

    SEEN[$current]=1

    while read -r dep; do
        [ -n "$dep" ] && QUEUE+=("$dep")
    done < <(grep -E '^[[:space:]]*import ' "${SRC}/${current}" | sed -E 's/.*"([^"]+)".*/\1/')
done

# mapfile rather than word-splitting an unquoted expansion: the old form relied on
# no proto path containing whitespace, which is true and was never checked.
mapfile -t FILES < <(printf '%s\n' "${!SEEN[@]}" | sort)
echo "    ${#FILES[@]} proto files in the closure"

echo "==> Copying and injecting PHP namespace options"
for f in "${FILES[@]}"; do
    mkdir -p "${WORK}/$(dirname "$f")"
    cp "${SRC}/${f}" "${WORK}/${f}"

    package="$(grep -E '^package ' "${WORK}/${f}" | head -n1 | sed -E 's/^package[[:space:]]+([a-zA-Z0-9_.]+);.*/\1/')"

    # NOTE: awk's -v assignment runs its own backslash-escape pass on the value
    # (POSIX-mandated: identical to string-constant escaping), collapsing "\\"
    # to "\" before the string ever reaches print. Proto3 string literals then
    # run a second escape pass when protoc parses the generated file, where
    # "\\" again collapses to "\". To end up with a single literal backslash
    # in the PHP namespace protoc sees, the value must therefore carry it as
    # four backslash characters here: awk's pass reduces \\\\ to \\, and
    # protoc's pass reduces that \\ to \.
    #
    # Messages go under this package's own root. Descriptor metadata goes under
    # GPBMetadata, which is where every PHP consumer of protobuf expects to find
    # it -- but namespaced by vendor, never at the bare GPBMetadata root. The bare
    # root is a global name two packages can both claim, and Composer resolves
    # that by merging the directories and silently using whichever it lists first.
    # google-cloud-php registers 238 prefixes under GPBMetadata and not one of
    # them is the bare root; this follows that.
    case "$package" in
        livekit)       php_ns='LiveKit\\\\Proto';        meta_ns='GPBMetadata\\\\LiveKit' ;;
        livekit.agent) php_ns='LiveKit\\\\Proto\\\\Agent'; meta_ns='GPBMetadata\\\\LiveKit\\\\Agent' ;;
        logger)        php_ns='LiveKit\\\\Proto\\\\Logger'; meta_ns='GPBMetadata\\\\LiveKit\\\\Logger' ;;
        *) echo "error: unmapped proto package '${package}' in ${f}" >&2; exit 1 ;;
    esac

    # Insert the two options immediately after the package declaration.
    awk -v php_ns="$php_ns" -v meta_ns="$meta_ns" '
        /^package / && !done {
            print
            print "option php_namespace = \"" php_ns "\";"
            print "option php_metadata_namespace = \"" meta_ns "\";"
            done = 1
            next
        }
        { print }
    ' "${WORK}/${f}" > "${WORK}/${f}.tmp" && mv "${WORK}/${f}.tmp" "${WORK}/${f}"
done

echo "==> Running protoc"

# Everything is generated into a staging directory and moved into place at the
# very end. Generating straight into src/Proto meant deleting 360 committed
# files and then hoping: a protoc that failed, or a Ctrl-C, left the tree empty
# and the package unloadable until someone thought to `git checkout src/Proto`.
# Nothing below touches src/Proto until the whole generation has succeeded.
STAGE="${BUILD_DIR}/stage"
rm -rf "$STAGE"
mkdir -p "$STAGE"
(cd "$WORK" && protoc --proto_path=. --php_out="$STAGE" "${FILES[@]}")

# protoc maps each namespace onto a directory path, so the stage now holds two
# trees: LiveKit/Proto/... for the messages and GPBMetadata/LiveKit/... for the
# descriptors. Separate them into the two roots the PSR-4 prefixes point at --
# `LiveKit\` => src/, so `LiveKit\Proto\Room` is src/Proto/Room.php, and
# `GPBMetadata\LiveKit\` => metadata/, so `GPBMetadata\LiveKit\LivekitRoom` is
# metadata/LivekitRoom.php.
MSG_STAGE="${STAGE}/LiveKit/Proto"
META_STAGE="${STAGE}/GPBMetadata/LiveKit"

for d in "$MSG_STAGE" "$META_STAGE"; do
    [ -d "$d" ] || { echo "error: protoc produced no ${d#"$STAGE"/}" >&2; exit 1; }
done

# Record which upstream revision this tree came from, as generated code so the
# proto-drift job catches a version bump the same way it catches any other change.
# Without this the pinned version lives only in this script and in prose, and the
# shipped package cannot tell you what it was built against.
PROTOCOL_COMMIT=$(git -C "${BUILD_DIR}/protocol" rev-parse HEAD)

# The only destructive steps, and the last ones. Note that src/ itself is never
# removed -- only src/Proto within it -- because the 70 hand-written files live
# there, and so does ProtocolVersion.php, written below.
rm -rf "$OUT_DIR" "$META_OUT_DIR"
mv "$MSG_STAGE" "$OUT_DIR"
mv "$META_STAGE" "$META_OUT_DIR"

# Generated by this script rather than by protoc, and its shape is this package's
# own -- so it belongs with the hand-written code it serves, not in src/Proto
# among protoc's output. Written after the swap: it is the last thing that can
# fail, and nothing above depends on it.
cat > "${VERSION_FILE}" <<PHP_EOF
<?php

# Generated by bin/generate-protos.sh. DO NOT EDIT!

declare(strict_types=1);

namespace LiveKit;

/**
 * The livekit/protocol revision the generated classes were produced from.
 */
final class ProtocolVersion
{
    public const string TAG = '${PROTOCOL_VERSION}';

    public const string COMMIT = '${PROTOCOL_COMMIT}';
}
PHP_EOF

msg_count=$(find "$OUT_DIR" -name '*.php' | wc -l | tr -d ' ')
meta_count=$(find "$META_OUT_DIR" -name '*.php' | wc -l | tr -d ' ')
echo "==> Generated ${msg_count} message files in src/Proto and ${meta_count} descriptor files in metadata/"
echo "    (protocol ${PROTOCOL_VERSION}, ${PROTOCOL_COMMIT:0:12})"
echo "==> Done. Review with: git diff --stat src/Proto metadata src/ProtocolVersion.php"
