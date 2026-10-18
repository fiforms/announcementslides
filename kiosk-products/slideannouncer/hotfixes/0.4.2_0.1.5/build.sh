#!/bin/bash
# Builds the 0.4.2_0.1.5 hotfix bundle (requires a device at 0.4.2_0.1.4). See
# ../README.md for the convention this directory follows.
#
# Boot splash fix: 0.4.2_0.1.4's painter exited after one paint, but on real
# hardware the framebuffer was wiped again within about a second (kiosk didn't
# start until ~18s). The painter now keeps repainting until the kiosk unit is
# active.
#
# - /usr/local/sbin/slide-announcer-boot-splash: copied from
#   slideannouncer/system/scripts/boot-splash.py at build time.
#
# Requires a reboot after install (the unit starts at boot).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"
CORE="$(cd "${PLATFORM}/.." && pwd)"

REQUIRED_VERSION="0.4.2_0.1.4"
NEW_VERSION="0.4.2_0.1.5"

# mktemp -d is 0700; the platform README explains why / must stay 0755.
STAGE="$(mktemp -d)"
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
install -D -m 755 "${CORE}/system/scripts/boot-splash.py" "${STAGE}/usr/local/sbin/slide-announcer-boot-splash"

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION"
