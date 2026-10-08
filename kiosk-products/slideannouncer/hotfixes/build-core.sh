#!/bin/bash
# Builds one of the PLATFORM's hotfix bundles (slideannouncer/image-builder/
# hotfixes/<version>/) for this product:
#
#   ./build-core.sh <version>      (or: npm run hotfix:build:core <version>)
#
# <version> is the hotfix directory's name, i.e. the platform part of the
# version it bumps to (e.g. 0.4.1). Platform hotfixes read the product's
# image/VERSION to make the pair <platform>_<product>, so PRODUCT_ROOT is set
# here. This product's own hotfixes are built with build.sh. See README.md.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLATFORM_HOTFIXES="$(cd "${HERE}/../../../slideannouncer/image-builder/hotfixes" && pwd)"
VERSION="${1:?usage: $0 <version>   (one of: $(cd "$PLATFORM_HOTFIXES" && ls -d [0-9]* 2>/dev/null | tr '\n' ' '))}"

if [ ! -x "${PLATFORM_HOTFIXES}/${VERSION}/build.sh" ]; then
	echo "build-core.sh: no executable ${PLATFORM_HOTFIXES}/${VERSION}/build.sh" >&2
	exit 1
fi

export PRODUCT_ROOT="$(cd "${HERE}/.." && pwd)"
exec "${PLATFORM_HOTFIXES}/${VERSION}/build.sh"
