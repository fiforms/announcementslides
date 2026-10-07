# Slide Announcer — device product

The signage product that runs on the kiosk platform in the
[`slideannouncer/`](../../slideannouncer) submodule: slide sync from this
app's `/api/slide-announcers/*` API, the full-screen slideshow, the Menu
overlay, the LAN video receiver (SRT/RIST) and Revelation peering.

```
image/      product.env, packages (mpv, ffmpeg), enable + units for the Revelation peer daemon
backend/    sync, widgets, pinning, srt_*, revelation, routes — the `products.slideannouncer` package
frontend/   Slideshow, MenuOverlay, SlideStage/Widget*, SRT + Revelation settings pages
```

Build (from the repo root; the platform is the pinned `slideannouncer/` submodule):

```bash
export PRODUCT_ROOT=$PWD/kiosk-products/slideannouncer
slideannouncer/image-builder/build.sh          # OS image + RAUC bundle
slideannouncer/local-app/package.sh            # local-app release only
slideannouncer/local-app/dev-deploy.sh user@host
slideannouncer/local-app/run-tests.sh          # needs the backend's requirements + pytest
```

How the pieces fit, and what a product may supply:
[`slideannouncer/docs/PRODUCTS.md`](../../slideannouncer/docs/PRODUCTS.md).
The server half is this app's `SlideAnnouncer*Controller`s, which implement
[`DEVICE_CONTRACT.md`](../../slideannouncer/docs/DEVICE_CONTRACT.md).
