# Sourced by product.sh and build-branded.sh:  . brand-env.sh <brand>
# Stages the slideannouncer product with kiosk-products/brands/<brand>/ overlaid
# and exports PRODUCT_ROOT, PRODUCT, PRODUCT_VERSION_SUFFIX and KIOSK_BRAND_NAME
# for the platform's package.sh / build.sh / run-tests.sh. The staging tree is
# removed when the calling script exits. PRODUCT stays "slideannouncer", so the
# RAUC compatible string, on-device PRODUCT file and OTA channel are unchanged.

_KP="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
_BRAND="${1:?brand name required}"
_BRAND_DIR="${_KP}/brands/${_BRAND}"
[ -f "${_BRAND_DIR}/brand.env" ] || { echo "No ${_BRAND_DIR}/brand.env" >&2; exit 1; }

HOSTNAME_PREFIX=""
BRAND_NAME=""
. "${_BRAND_DIR}/brand.env"
if ! [[ "$HOSTNAME_PREFIX" =~ ^[A-Za-z0-9-]+$ ]]; then
	echo "brand.env: HOSTNAME_PREFIX must be letters, digits and hyphens" >&2
	exit 1
fi

_TMP="$(mktemp -d)"
trap 'rm -rf "$_TMP"' EXIT
_STAGED="${_TMP}/slideannouncer"
rsync -a --exclude '__pycache__' "${_KP}/slideannouncer/" "${_STAGED}/"

# Hostname prefix: replace any existing line, then append ours.
sed -i '/^[[:space:]]*HOSTNAME_PREFIX=/d' "${_STAGED}/image/product.env"
printf '\n# Brand: %s\nHOSTNAME_PREFIX=%s\n' "$_BRAND" "$HOSTNAME_PREFIX" >> "${_STAGED}/image/product.env"

# One PNG feeds the boot splash, the wizard's Welcome screen and About.
[ ! -f "${_BRAND_DIR}/splash.png" ] || cp "${_BRAND_DIR}/splash.png" "${_STAGED}/image/splash.png"

export PRODUCT_ROOT="$_STAGED"
export PRODUCT=slideannouncer
# Read by the frontend build (vite.config.js) for the About screen.
export KIOSK_BRAND_NAME="${BRAND_NAME:-$_BRAND}"
export PRODUCT_VERSION_SUFFIX="slideannouncer-$(git -C "$_KP" log -1 --format=%h -- slideannouncer "brands/${_BRAND}" 2>/dev/null || echo nogit)-${_BRAND}"
