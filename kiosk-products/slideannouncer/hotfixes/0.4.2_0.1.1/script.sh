#!/bin/bash
# Runs once on the device after files/ is extracted and before VERSION is
# bumped. $ROOT is the patched rootfs's bind-mount, not live /.
set -euo pipefail

# The daemon's old, misnamed install path (nothing references it).
rm -f "$ROOT/usr/local/sbin/revelation-peer-daemon.py"
