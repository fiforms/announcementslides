#!/bin/bash
# Builds one of this product's hotfix bundles:
#
#   ./build.sh <new-version>        (or: npm run hotfix:slideannouncer -- <new-version>)
#
# Runs <new-version>/build.sh with PRODUCT_ROOT set, so the bundle is
# stamped with this product's RAUC `compatible` and named
# slideannouncer-<new>.hotfix.from.<required>.raucb. Needs the same
# RAUC_CERT_PATH/RAUC_KEY_PATH as the platform's image-builder/.env, and
# `rauc` installed. The bundle lands in slideannouncer/image-builder/deploy/.
# See README.md here first: a hotfix patches the OS root filesystem only.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NEW_VERSION="${1:?usage: $0 <new-version>   (one of: $(cd "$HERE" && ls -d [0-9]* 2>/dev/null | tr '\n' ' '))}"

if [ ! -x "${HERE}/${NEW_VERSION}/build.sh" ]; then
	echo "build.sh: no executable ${NEW_VERSION}/build.sh here" >&2
	exit 1
fi

export PRODUCT_ROOT="$(cd "${HERE}/.." && pwd)"
exec "${HERE}/${NEW_VERSION}/build.sh"
