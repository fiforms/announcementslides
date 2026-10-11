#!/bin/bash
# Builds the 0.4.2_0.1.2 hotfix bundle (requires a device at 0.4.2_0.1.1). See
# ../README.md for the convention this directory follows.
#
# Adds the Revelation `rist-screen` command (peering protocol 2.1) to the peer
# daemon: when a master streams its screen it sends `start` with a port,
# stream id and optional encryption, and the daemon records it in
# /data/status/rist-push.json for the backend's receiver to call back as a RIST
# caller (`stop` removes it). The host is never taken from the command; it is
# the master's own address.
#
# - /usr/local/sbin/slide-announcer-revelation-peer: the daemon, copied from
#   image/system/scripts/ at build time (not committed here, so it can't drift).
#
# Requires a reboot after install: the hook can't restart a running unit from
# the live device shell (see make-hotfix-bundle.sh).
#
# The receiving side (backend/srt_sink.py, backend/srt_stream_bridge.py) is app
# code and ships through the app updater as 0.4.1_0.1.5, not in this bundle.
# Until that update is installed, a pushed stream is recorded but not played.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"

REQUIRED_VERSION="0.4.2_0.1.1"
NEW_VERSION="0.4.2_0.1.2"

STAGE="$(mktemp -d)"
# mktemp -d is 0700; make-hotfix-bundle.sh also pins this, but be explicit.
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
install -D -m 755 "${PRODUCT_ROOT}/image/system/scripts/slide-announcer-revelation-peer" \
	"${STAGE}/usr/local/sbin/slide-announcer-revelation-peer"

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION"
