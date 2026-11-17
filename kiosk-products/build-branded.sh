#!/usr/bin/env bash
# Build the slideannouncer product with a customer's branding, without
# touching the product or the platform submodule.
#
#   kiosk-products/build-branded.sh <brand> [build.sh args, e.g. --virt]
#
# <brand> is a directory kiosk-products/brands/<brand>/ holding brand.env
# (HOSTNAME_PREFIX) and optionally splash.png. The product is copied to a
# throwaway tree, the brand is overlaid, and the platform's build.sh runs
# with PRODUCT_ROOT pointing at that copy. PRODUCT stays "slideannouncer",
# so the RAUC compatible string, on-device PRODUCT file and OTA channel are
# unchanged: a branded device is still a slideannouncer to the server.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(dirname "$HERE")"
BRAND="${1:?usage: $0 <brand> [build.sh args]}"; shift
BRAND_DIR="${HERE}/brands/${BRAND}"
[ -f "${BRAND_DIR}/brand.env" ] || { echo "No ${BRAND_DIR}/brand.env" >&2; exit 1; }

HOSTNAME_PREFIX=""
. "${BRAND_DIR}/brand.env"
if ! [[ "$HOSTNAME_PREFIX" =~ ^[A-Za-z0-9-]+$ ]]; then
	echo "brand.env: HOSTNAME_PREFIX must be letters, digits and hyphens" >&2
	exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGED="${TMP}/slideannouncer"
rsync -a --exclude '__pycache__' "${HERE}/slideannouncer/" "${STAGED}/"

# Hostname prefix: replace any existing line, then append ours.
sed -i '/^[[:space:]]*HOSTNAME_PREFIX=/d' "${STAGED}/image/product.env"
printf '\n# Brand: %s\nHOSTNAME_PREFIX=%s\n' "$BRAND" "$HOSTNAME_PREFIX" >> "${STAGED}/image/product.env"

# One PNG feeds the boot splash, the wizard's Welcome screen and About.
[ ! -f "${BRAND_DIR}/splash.png" ] || cp "${BRAND_DIR}/splash.png" "${STAGED}/image/splash.png"

export PRODUCT_ROOT="$STAGED"
export PRODUCT=slideannouncer
export PRODUCT_VERSION_SUFFIX="slideannouncer-$(git -C "$HERE" log -1 --format=%h -- slideannouncer brands/"$BRAND" 2>/dev/null || echo nogit)-${BRAND}"
cd "$REPO"
slideannouncer/image-builder/build.sh "$@"
