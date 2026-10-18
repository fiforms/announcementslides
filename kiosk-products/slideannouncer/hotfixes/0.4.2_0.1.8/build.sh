#!/bin/bash
# Builds the 0.4.2_0.1.8 hotfix bundle (requires a device at 0.4.2_0.1.7). See
# ../README.md for the convention this directory follows.
#
# Boot splash needs a 1080p kernel console: on a Pi 4 at the default 4K the vc4
# framebuffer is 16bpp and shows nothing we write (the logo flashed once on the
# early firmware framebuffer, then went black). Adds
# video=HDMI-A-1:1920x1080@60 and video=HDMI-A-2:1920x1080@60 to both slots'
# cmdline.txt (see script.sh). labwc still applies the device's own resolution
# setting later. No files are shipped; this is a boot-partition edit only.
#
# Takes effect on the next boot.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"

REQUIRED_VERSION="0.4.2_0.1.7"
NEW_VERSION="0.4.2_0.1.8"

# No files to ship; an empty stage (0755, see the platform README: never change /'s mode).
STAGE="$(mktemp -d)"
chmod 755 "$STAGE"
trap 'rm -rf "$STAGE"' EXIT

"${PLATFORM}/make-hotfix-bundle.sh" "$STAGE" "$REQUIRED_VERSION" "$NEW_VERSION" "${HERE}/script.sh"
