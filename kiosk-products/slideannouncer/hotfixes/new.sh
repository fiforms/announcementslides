#!/bin/bash
# Scaffolds a new hotfix directory:
#
#   ./new.sh <required-version> <new-version> "<what it fixes>"
#   (or: npm run hotfix:new:product 0.4.1_0.1.0 0.4.1_0.1.1 "what it fixes")
#
# <required-version> must be exactly what /opt/slide-announcer/VERSION reads
# on the devices it should patch, and <new-version> is what it becomes. Both
# are the pair <platform>_<product> (e.g. 0.4.1_0.1.0 -> 0.4.1_0.1.1 for a
# product-only change). Hotfix versions form ONE chain per device (the
# platform's own hotfixes use it too), so check the latest before choosing —
# see README.md.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
USAGE="usage: $0 <required-version> <new-version> \"<what it fixes>\""
REQUIRED="${1:?$USAGE}"
NEW="${2:?$USAGE}"
TITLE="${3:?$USAGE}"

for v in "$REQUIRED" "$NEW"; do
	[[ "$v" =~ ^[0-9]+\.[0-9]+\.[0-9]+(_[0-9]+\.[0-9]+\.[0-9]+)?$ ]] || { echo "new.sh: '$v' is not X.Y.Z or <platform>_<product> (X.Y.Z_X.Y.Z)" >&2; exit 1; }
done
DEST="${HERE}/${NEW}"
if [ -e "$DEST" ]; then
	echo "new.sh: ${DEST} already exists" >&2
	exit 1
fi

mkdir -p "${DEST}/files"
cat > "${DEST}/build.sh" <<BUILD
#!/bin/bash
# Builds the ${NEW} hotfix bundle (requires a device at ${REQUIRED}). See
# ../README.md for the convention this directory follows.
#
# ${TITLE}
#
# Files (under files/, copied onto the device's root):
#   (list each one and why)
#
# Requires a reboot after install if it touches a unit or nginx config
# (the hook can't restart running services); say so here if it does.
set -euo pipefail

HERE="\$(cd "\$(dirname "\${BASH_SOURCE[0]}")" && pwd)"
# PRODUCT_ROOT is set by ../build.sh; allow running this script directly too.
export PRODUCT_ROOT="\${PRODUCT_ROOT:-\$(cd "\${HERE}/../.." && pwd)}"
PLATFORM="\$(cd "\${PRODUCT_ROOT}/../../slideannouncer/image-builder" && pwd)"

REQUIRED_VERSION="${REQUIRED}"
NEW_VERSION="${NEW}"

# make-hotfix-bundle.sh needs the directory to exist even if it only holds
# files created here (git can't track an empty one).
mkdir -p "\${HERE}/files"
SCRIPT_ARG=()
[ ! -f "\${HERE}/script.sh" ] || SCRIPT_ARG=("\${HERE}/script.sh")

"\${PLATFORM}/make-hotfix-bundle.sh" "\${HERE}/files" "\$REQUIRED_VERSION" "\$NEW_VERSION" "\${SCRIPT_ARG[@]}"
BUILD
chmod +x "${DEST}/build.sh"

cat > "${DEST}/script.sh" <<'SCRIPT'
#!/bin/bash
# Runs once on the device after files/ is extracted and before VERSION is
# bumped (delete this file if the hotfix is a pure file drop). It is NOT
# chrooted: $ROOT is the patched rootfs's bind-mount, so target it
# explicitly, e.g.
#   systemctl --root="$ROOT" enable foo.service
#   rm -f "$ROOT/etc/..."
# A nonzero exit aborts the hotfix before VERSION is bumped.
set -euo pipefail
SCRIPT
chmod +x "${DEST}/script.sh"

echo "Created ${DEST}"
echo "  1. put the files to patch under ${DEST}/files/ (same layout as the device's /)"
echo "  2. edit build.sh's header comment (and script.sh, or delete it)"
echo "  3. build: npm run hotfix:build:product ${NEW}"
