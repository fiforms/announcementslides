#!/bin/bash
# Builds the 0.4.2_0.1.6 hotfix bundle (requires a device at 0.4.2_0.1.5). See
# ../README.md for the convention this directory follows.
#
# Boot splash fix: on a Pi 4 at 4K the vc4 framebuffer is 16bpp (RGB565), which
# the painter didn't support, so nothing was ever drawn once vc4 replaced the
# firmware framebuffer (only the early 32bpp simplefb showed the logo, briefly).
# The painter now handles 16bpp and explicitly unblanks the framebuffer.
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

REQUIRED_VERSION="0.4.2_0.1.5"
NEW_VERSION="0.4.2_0.1.6"

# mktemp -d is 0700; the platform README explains why / must stay 0755.
STAGE="$(mktemp -d)"
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
install -D -m 755 "${CORE}/system/scripts/boot-splash.py" "${STAGE}/usr/local/sbin/slide-announcer-boot-splash"

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION"
