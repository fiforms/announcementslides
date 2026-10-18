#!/bin/bash
# Builds the 0.4.2_0.1.3 hotfix bundle (requires a device at 0.4.2_0.1.2). See
# ../README.md for the convention this directory follows.
#
# Revelation peer daemon: `open-presentation` URLs are built by the master from
# its own page origin (http://localhost:8000/...), and each peer must point them
# at the master's real address or the kiosk shows {"detail":"not found"}. The
# daemon now rewrites private/local hosts to the master's endpoint (mDNS, else
# the pairing hints), including a nested `src` param, and rejects non-http(s)
# URLs. Public URLs and unknown endpoints are left unchanged.
#
# - /usr/local/sbin/slide-announcer-revelation-peer: the daemon, copied from
#   image/system/scripts/ at build time (not committed here, so it can't drift).
#
# Requires a reboot after install: the hook can't restart a running unit from
# the live device shell (see make-hotfix-bundle.sh).
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"

REQUIRED_VERSION="0.4.2_0.1.2"
NEW_VERSION="0.4.2_0.1.3"

STAGE="$(mktemp -d)"
# mktemp -d is 0700; make-hotfix-bundle.sh also pins this, but be explicit.
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
install -D -m 755 "${PRODUCT_ROOT}/image/system/scripts/slide-announcer-revelation-peer" \
	"${STAGE}/usr/local/sbin/slide-announcer-revelation-peer"

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION"
