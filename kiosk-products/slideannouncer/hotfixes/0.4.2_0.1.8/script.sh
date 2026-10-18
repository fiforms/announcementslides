#!/bin/bash
# Runs on-device after files/ is extracted (see make-hotfix-bundle.sh and
# ../README.md). $ROOT is the patched rootfs's bind-mount, not live /.
set -euo pipefail

# Boot at 1080p so the boot splash is visible: the vc4 framebuffer at the
# default 4K is a 16bpp console that shows nothing we write. Takes effect on
# the next boot. Adds each token only if missing (a device may already have
# one from manual testing).
BOOTFW="/boot/firmware"

slide-announcer-bootfw-remount rw
trap 'slide-announcer-bootfw-remount ro' EXIT

# Both slots' cmdline.txt: a hotfix only patches the booted slot's rootfs, but
# the other slot's cmdline is the one a later tryboot would load.
for f in "$BOOTFW"/slotA/cmdline.txt "$BOOTFW"/slotB/cmdline.txt; do
	[ -f "$f" ] || continue
	for conn in HDMI-A-1 HDMI-A-2; do
		grep -q "video=${conn}:" "$f" || sed -i "s/\$/ video=${conn}:1920x1080@60/" "$f"
	done
done
