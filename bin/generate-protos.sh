#!/usr/bin/env bash
#
# Regenerates src/Proto from a pinned livekit/protocol tag.
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

PROTOCOL_VERSION="v1.52.0"
MIN_PROTOC_VERSION="29.3"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${REPO_ROOT}/build/protos"
OUT_DIR="${REPO_ROOT}/src/Proto"

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
#   livekit_internal, livekit_analytics, livekit_rtc -> server internals
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
)

echo "==> Resolving transitive import closure"
declare -A SEEN=()
QUEUE=("${ROOTS[@]}")

while [ ${#QUEUE[@]} -gt 0 ]; do
    current="${QUEUE[0]}"
    QUEUE=("${QUEUE[@]:1}")

    [ -n "${SEEN[$current]:-}" ] && continue
    # google/protobuf/*.proto ships with protoc itself; never copy or compile it
    case "$current" in google/protobuf/*) continue ;; esac
    [ -f "${SRC}/${current}" ] || { echo "error: missing proto ${current}" >&2; exit 1; }

    SEEN[$current]=1

    while read -r dep; do
        [ -n "$dep" ] && QUEUE+=("$dep")
    done < <(grep -E '^[[:space:]]*import ' "${SRC}/${current}" | sed -E 's/.*"([^"]+)".*/\1/')
done

FILES=()
for f in "${!SEEN[@]}"; do FILES+=("$f"); done
IFS=$'\n' FILES=($(sort <<<"${FILES[*]}")); unset IFS
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
    case "$package" in
        livekit)       php_ns='LiveKit\\\\Proto';        meta_ns='LiveKit\\\\Proto\\\\Meta' ;;
        livekit.agent) php_ns='LiveKit\\\\Proto\\\\Agent'; meta_ns='LiveKit\\\\Proto\\\\Meta\\\\Agent' ;;
        logger)        php_ns='LiveKit\\\\Proto\\\\Logger'; meta_ns='LiveKit\\\\Proto\\\\Meta\\\\Logger' ;;
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
rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR"
(cd "$WORK" && protoc --proto_path=. --php_out="$OUT_DIR" "${FILES[@]}")

# protoc writes LiveKit/Proto/... under the out dir because the namespace maps to
# a directory path. Flatten that so the PSR-4 root `LiveKit\` => src/ resolves
# `LiveKit\Proto\Room` at src/Proto/Room.php.
if [ -d "${OUT_DIR}/LiveKit/Proto" ]; then
    mv "${OUT_DIR}/LiveKit/Proto"/* "$OUT_DIR"/
    rm -rf "${OUT_DIR}/LiveKit"
fi

echo "==> Generated $(find "$OUT_DIR" -name '*.php' | wc -l | tr -d ' ') PHP files"
echo "==> Done. Review with: git diff --stat src/Proto"
