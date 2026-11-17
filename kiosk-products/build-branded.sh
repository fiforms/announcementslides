#!/usr/bin/env bash
# Build the slideannouncer OS image with a customer's branding, without
# touching the product or the platform submodule.
#
#   kiosk-products/build-branded.sh <brand> [build.sh args, e.g. --virt]
#
# <brand> is kiosk-products/brands/<brand>/ (brand.env: HOSTNAME_PREFIX,
# BRAND_NAME; optional splash.png). See brand-env.sh. `npm run
# build:slideannouncer -- --branding[=<brand>]` (product.sh) also runs the tests.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BRAND="${1:?usage: $0 <brand> [build.sh args]}"; shift
. "${HERE}/brand-env.sh" "$BRAND"
cd "$(dirname "$HERE")"
slideannouncer/image-builder/build.sh "$@"
