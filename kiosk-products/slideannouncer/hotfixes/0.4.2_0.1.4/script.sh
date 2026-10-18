#!/bin/bash
# Runs on-device after files/ is extracted (see make-hotfix-bundle.sh and
# ../README.md). $ROOT is the patched rootfs's bind-mount, not live /.
set -euo pipefail

# A plain file drop doesn't create the WantedBy= symlink; enable the unit the
# way `systemctl enable` at image-build time does. Takes effect next boot.
systemctl --root="$ROOT" enable slide-announcer-splash.service
