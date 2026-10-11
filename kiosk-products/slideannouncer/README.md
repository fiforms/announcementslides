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

Product docs: [`docs/MULTI_SHOW_IMPLEMENTATION.md`](docs/MULTI_SHOW_IMPLEMENTATION.md)
(the multi-show kiosk spec).

## LAN video receiver (SRT/RIST)

Not a separate systemd unit — `backend/srt_stream_bridge.py` is an in-process
asyncio task started with the backend (same pattern as the platform's
heartbeat), replacing an earlier standalone mpv/DRM-takeover daemon. It polls
UDP port 7002 (a plain bound socket — nothing else listens there, so the
bridge has to hold it to see any traffic) using the passphrase configured in
Settings > SRT Sink (`backend/srt_sink.py`, `/data/status/srt-sink.json`).
On any datagram it hands off to a real ffmpeg listener that remuxes the
still-encoded H.264 (`-c copy`, no decode/re-encode) into fragmented MP4 and
forwards it to the kiosk page over the WebSocket `/api/local/srt-sink/stream`,
which plays it inline via MediaSource Extensions. Chromium never stops being
the active kiosk process, so no display takeover or kiosk restart is involved.
The device addresses itself on the LAN by its mDNS hostname
(`<hostname>.local`).

Receiver modes (`srt_sink.MODES`): `srt` and `rist_unicast` listen on this
device; `rist_multicast` joins a group; `rist_caller` is the reverse — the
sender (e.g. OBS with a RIST output in listener mode, `rist://@0.0.0.0:5000`)
listens and this device connects out to its configured host/port, so one
sender can feed many devices. There is no separate probe for "is the sender
up": ffmpeg's RIST caller blocks cheaply until media arrives, and the
bridge's idle timeout ends the stream when it stops, relaunching ffmpeg
(at most every `CALLER_RETRY_INTERVAL_SECONDS`) to wait for the next one.

Its settings ride on the platform heartbeat as `srt_sink_*` extension keys
(server side: `App\Support\SlideAnnouncer\VideoReceiverHeartbeatExtension`).
