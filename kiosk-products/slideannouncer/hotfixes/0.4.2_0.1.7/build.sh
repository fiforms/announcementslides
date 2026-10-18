#!/bin/bash
# Builds the 0.4.2_0.1.7 hotfix bundle (requires a device at 0.4.2_0.1.6). See
# ../README.md for the convention this directory follows.
#
# Faster boot: slide-announcer-firstboot.service held the kiosk back ~6s on
# every boot while detect_setup_mode() polled for WiFi to associate, for a
# value only shown on the local status API. firstboot now writes an instant
# result and refreshes it (with the WiFi wait) in a detached systemd-run unit.
#
# - /opt/slide-announcer/provisioning/firstboot.py: copied from
#   slideannouncer/provisioning/firstboot.py at build time.
#
# Takes effect on the next boot (firstboot runs once per boot).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"
CORE="$(cd "${PLATFORM}/.." && pwd)"

REQUIRED_VERSION="0.4.2_0.1.6"
NEW_VERSION="0.4.2_0.1.7"

# mktemp -d is 0700; the platform README explains why / must stay 0755.
STAGE="$(mktemp -d)"
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
install -D -m 755 "${CORE}/provisioning/firstboot.py" "${STAGE}/opt/slide-announcer/provisioning/firstboot.py"

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION"
