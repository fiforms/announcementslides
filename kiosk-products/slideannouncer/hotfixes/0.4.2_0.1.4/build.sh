#!/bin/bash
# Builds the 0.4.2_0.1.4 hotfix bundle (requires a device at 0.4.2_0.1.3). See
# ../README.md for the convention this directory follows.
#
# Boot splash: paints a logo on the framebuffer early in boot instead of a
# black screen (slideannouncer/system/scripts/boot-splash.py). Files are copied
# from the repo at build time, not committed here, so they can't drift:
#
# - /usr/local/sbin/slide-announcer-boot-splash: the painter (stdlib only —
#   hotfixes can't install packages, so the image is pre-scaled raw).
# - /etc/systemd/system/slide-announcer-splash.service: runs it in sysinit;
#   script.sh enables it.
# - /usr/share/slide-announcer/splash.raw: image/splash.png, converted by
#   slideannouncer/image-builder/splash-convert.py (needs Pillow on the build
#   host).
#
# Requires a reboot after install to see the splash (the unit starts at boot).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"
CORE="$(cd "${PLATFORM}/.." && pwd)"

REQUIRED_VERSION="0.4.2_0.1.3"
NEW_VERSION="0.4.2_0.1.4"

SPLASH_PNG="${CORE}/system/splash/splash.png"
[ ! -f "${PRODUCT_ROOT}/image/splash.png" ] || SPLASH_PNG="${PRODUCT_ROOT}/image/splash.png"

# mktemp -d is 0700; the platform README explains why / must stay 0755.
STAGE="$(mktemp -d)"
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT
install -D -m 755 "${CORE}/system/scripts/boot-splash.py" "${STAGE}/usr/local/sbin/slide-announcer-boot-splash"
install -D -m 644 "${CORE}/system/slide-announcer-splash.service" "${STAGE}/etc/systemd/system/slide-announcer-splash.service"
install -d -m 755 "${STAGE}/usr/share/slide-announcer"
"${PLATFORM}/splash-convert.py" "$SPLASH_PNG" "${STAGE}/usr/share/slide-announcer/splash.raw"
chmod 644 "${STAGE}/usr/share/slide-announcer/splash.raw"

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION" "${HERE}/script.sh"
