#!/bin/bash
# Builds the 0.4.2_0.1.9 hotfix bundle (requires a device at 0.4.2_0.1.8). See
# ../README.md for the convention this directory follows.
#
# apply-screen-resolution.sh guessed WAYLAND_DISPLAY=wayland-1, but labwc
# creates wayland-0, so wlr-randr never connected: the Settings -> Screens
# toggle and the boot-time apply were both silent no-ops (TV stayed at 4K).
# The script now looks for the real socket in $XDG_RUNTIME_DIR.
#
# Files (under files/, copied onto the device's root):
#   usr/local/sbin/slide-announcer-apply-screen-resolution
#
# The backend half (pairing.py's stray AUDIO_OUTPUT_FILE.chmod that 500'd
# POST /api/local/screen-resolution) ships through the app updater.
# Takes effect on the next call/boot; no unit changes.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="${PRODUCT_ROOT:-$(cd "${HERE}/../.." && pwd)}"
PLATFORM="$(cd "${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"

REQUIRED_VERSION="0.4.2_0.1.8"
NEW_VERSION="0.4.2_0.1.9"

# make-hotfix-bundle.sh needs the directory to exist even if it only holds
# files created here (git can't track an empty one).
mkdir -p "${HERE}/files"
SCRIPT_ARG=()
[ ! -f "${HERE}/script.sh" ] || SCRIPT_ARG=("${HERE}/script.sh")

"${PLATFORM}/make-hotfix-bundle.sh" "${HERE}/files" "$REQUIRED_VERSION" "$NEW_VERSION" "${SCRIPT_ARG[@]}"
