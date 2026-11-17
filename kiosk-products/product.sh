#!/usr/bin/env bash
# Entry point for `npm run package:slideannouncer` and `npm run build:slideannouncer`.
#
#   product.sh package|build [--branding[=NAME] | --no-branding] [extra args]
#
#   package   local-app release tarball only (slideannouncer/local-app/package.sh)
#   build     tests, then the OS image + RAUC bundle + virt image (build.sh --virt)
#
# Branding must be chosen explicitly, so a run can never ship a customer's
# branding (or drop it) by accident: without --branding or --no-branding it
# fails. --no-branding is the stock product. --branding builds with kiosk-products/brands/NAME/ (see
# brand-env.sh); with no NAME it uses the one brand present besides `example`,
# and errors if there are none or several. Extra args go to build.sh (build)
# or package.sh (package). Through npm:  npm run build:slideannouncer -- --branding
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(dirname "$HERE")"

ACTION="${1:?usage: $0 package|build [--branding[=NAME] | --no-branding] [args]}"; shift
BRAND="" ; WANT_BRAND=0; CHOSEN=0; ARGS=()
for a in "$@"; do
	case "$a" in
		--branding)      WANT_BRAND=1; CHOSEN=1 ;;
		--branding=*)    WANT_BRAND=1; CHOSEN=1; BRAND="${a#--branding=}" ;;
		--no-branding)   WANT_BRAND=0; CHOSEN=1; BRAND="" ;;
		*)               ARGS+=("$a") ;;
	esac
done

if [ "$CHOSEN" = 0 ]; then
	echo "Say whether this is a branded build: pass --branding[=NAME] or --no-branding" >&2
	echo "  e.g. npm run ${ACTION}:slideannouncer -- --no-branding" >&2
	exit 1
fi

if [ "$WANT_BRAND" = 1 ]; then
	if [ -z "$BRAND" ]; then
		mapfile -t FOUND < <(find "${HERE}/brands" -mindepth 2 -maxdepth 2 -name brand.env -printf '%h\n' | xargs -r -n1 basename | grep -vx example | sort)
		case "${#FOUND[@]}" in
			1) BRAND="${FOUND[0]}" ;;
			0) echo "--branding: no brand in kiosk-products/brands/ (copy the example)" >&2; exit 1 ;;
			*) echo "--branding: several brands (${FOUND[*]}); pick one with --branding=NAME" >&2; exit 1 ;;
		esac
	fi
	echo "==> Branded build: ${BRAND}"
	. "${HERE}/brand-env.sh" "$BRAND"
else
	export PRODUCT_ROOT="${HERE}/slideannouncer"
fi

cd "$REPO"
case "$ACTION" in
	package) slideannouncer/local-app/package.sh "${ARGS[@]}" ;;
	build)
		slideannouncer/local-app/run-tests.sh -q
		slideannouncer/image-builder/build.sh --virt "${ARGS[@]}" ;;
	*) echo "unknown action: $ACTION (package|build)" >&2; exit 1 ;;
esac
