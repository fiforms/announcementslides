#!/bin/bash
# Builds the 0.4.2_0.1.1 hotfix bundle (requires a device at 0.4.2_0.1.0). See
# ../README.md for the convention this directory follows.
#
# Installs the Revelation peering daemon under the name its systemd unit runs.
# slide-announcer-revelation-peer.service has ExecStart=/usr/local/sbin/
# slide-announcer-revelation-peer, but since the product split (0.4.1) the image
# build installs product scripts under their own file names, so the daemon
# landed as /usr/local/sbin/revelation-peer-daemon.py and the unit crash-looped
# ("can't open file"). Nothing kept a paired master connected, so pairing
# appeared broken. The source script is now named for what the unit runs, so
# images built from 0.4.2_0.1.1 on need no hotfix.
#
# - /usr/local/sbin/slide-announcer-revelation-peer: the daemon, copied from
#   image/system/scripts/ at build time (not committed here, so it can't drift).
# - script.sh removes the misnamed /usr/local/sbin/revelation-peer-daemon.py.
#
# Requires a reboot after install: the hook can't restart a running unit from
# the live device shell (see make-hotfix-bundle.sh). The unit is already
# enabled and restarting every 5s, so it starts working on the next boot.
#
# The matching SRT/RIST idle-stream fix (backend/srt_stream_bridge.py) is app
# code and ships through the app updater as 0.4.1_0.1.4, not in this bundle.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"

REQUIRED_VERSION="0.4.2_0.1.0"
NEW_VERSION="0.4.2_0.1.1"

STAGE="$(mktemp -d)"
# mktemp -d is 0700; make-hotfix-bundle.sh also pins this, but be explicit.
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
install -D -m 755 "${PRODUCT_ROOT}/image/system/scripts/slide-announcer-revelation-peer" \
	"${STAGE}/usr/local/sbin/slide-announcer-revelation-peer"

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION" "${HERE}/script.sh"
