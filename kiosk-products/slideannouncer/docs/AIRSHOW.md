# AirShow screen mirroring

The Slide Announcer receives screen mirroring through
[AirShow](https://github.com/fiforms/AirShow): AirPlay (iPhone, iPad, Mac),
Miracast (Windows Win+K, Android Smart View over Wi-Fi Direct), Google Cast
(including GNOME Network Displays), and DLNA (untested). AirShow's own kiosk
reference is `docs/session-hooks.md` in that repo, installed on the device as
`/usr/share/doc/airshow/session-hooks.md`.

**Status:** built and unit-tested on a workstation, using stubs for AirShow and
Chromium. Not yet tried on a Pi image built this way. See *To verify on
hardware* below.

## The two modes

| | AirShow mode (attended) | Background mode (unattended) |
|---|---|---|
| Started by | Menu → *Screen Mirroring…* → **Start AirShow** (behind the Settings PIN) | Settings → Screen Mirroring → *Allow mirroring during the slideshow* |
| Unit | `slide-announcer-airshow.service` (`Conflicts=` the kiosk) | inside `slide-announcer-kiosk.service` |
| New senders | Allow/Deny prompt, answered with the remote (Enter allows) | rejected silently (`--approval known-only`) |
| Leaves by | Home or Back on the remote (`--quit-keys 172,158`) | the sender disconnecting (≈5 s later) |

Both modes share one settings file, `/data/airshow/AirShow/AirShow.conf`
(`XDG_CONFIG_HOME=/data/airshow`), so a sender approved in AirShow mode is
admitted in background mode. It has to live on `/data`, because the kiosk
user's home is on the tmpfs `/var` overlay. The background switch is
`/data/status/airshow.json`.

### AirShow mode

`systemctl start slide-announcer-airshow.service` (local app:
`POST /api/local/airshow/start`). `Conflicts=` stops the slideshow first. The
unit runs the platform's `kiosk-start.sh` with
`KIOSK_SESSION_WRAPPER="slide-announcer-airshow session attended"`, so labwc,
HDMI audio, and screen resolution are set up exactly as for the slideshow. Then
it runs AirShow instead of Chromium. When AirShow exits, labwc exits and the
unit ends. `ExecStopPost=slide-announcer-airshow return-to-kiosk` then starts
the slideshow again. It skips that while the display is asleep (the sleep marker
is written before the stop) and while the system is shutting down. A crash also
lands back on the slideshow; the unit has no `Restart=`.

### Background mode

`product.env` sets `KIOSK_SESSION_WRAPPER="slide-announcer-airshow session
background"` for the kiosk, so the wrapper always runs. With the switch off, it
execs Chromium, exactly as before. With the switch on, it:

1. starts AirShow with `NOTIFY_SOCKET` pointed at its own socket, and waits up
   to 30 s for `READY=1` (AirShow's window is on screen);
2. starts Chromium, which maps on top of AirShow;
3. polls `$XDG_RUNTIME_DIR/airshow-casting`, which AirShow's session hooks
   (`slide-announcer-airshow hook`) set to `start`/`end`, and stops Chromium
   while it says `start`, revealing AirShow, then starts it again afterward;
4. exits if Chromium exits on its own, so labwc exits and the kiosk unit's
   `Restart=always` recovers it, as before. If AirShow dies, the slideshow
   continues without it until the kiosk restarts.

After a session, Chromium restarts on `/kiosk`. Its state and CDP connection
start over, which takes a few seconds; that's the price of the stop/start design.
Changing the switch restarts the kiosk unit to apply it.

## Image pieces

| Path (`image/`) | What |
|---|---|
| `debs.lock` | pinned AirShow release `.deb` (URL + sha256), fetched by `build.sh` |
| `packages` | AirShow's Recommends, listed explicitly: `qt6-wayland` (without it there is no window under labwc), `iw`, `gstreamer1.0-pipewire` |
| `rootfs/etc/NetworkManager/conf.d/90-wifi-powersave-off.conf` | Wi-Fi power saving off for the station connection (bursts, "connection unstable", and half the Wi-Fi Direct link rate with it on) |
| `system/slide-announcer-airshow.service` | AirShow mode |
| `system/scripts/slide-announcer-airshow` | session wrapper (both modes), hook, return-to-kiosk, `list`/`forget` |
| `product.env` | `KIOSK_SESSION_WRAPPER`, `KIOSK_SCREEN_UNITS` (platform seams, `slideannouncer/docs/PRODUCTS.md`) |

Needs a full image or RAUC bundle, not a hotfix (new apt packages).

Permissions are already in place on the platform. `slideannouncer` is in
`netdev` (wpa_supplicant D-Bus), polkit grants it every
`org.freedesktop.NetworkManager.*` action (Android Miracast's temporary
`wifi-p2p` connection) and start/stop of `slide-announcer-*.service`, and both
units have `PAMName=login` (an active local session). NetworkManager keeps
managing `p2p-dev-wlan0`; don't mark it unmanaged.

## Interactions

- **Display sleep (Power key, Settings):** stops the kiosk and
  `KIOSK_SCREEN_UNITS`. Wake starts the slideshow, not AirShow mode.
- **Remote keys:** Power (116) stays with logind/the power-button service and is
  never a quit key. `volume-key-monitor.py` must keep not grabbing
  (`EVIOCGRAB`) the remote, or AirShow stops getting Home/Back.
- **Escape hatch / kiosk guard:** both talk to Chromium over CDP. With Chromium
  stopped (AirShow mode, or casting in background mode), the guard just
  retries. An escape-hatch trigger fails to navigate, so it restarts the kiosk
  unit, which ends AirShow mode or the cast. That's the intended way out.
- **Revelation peer:** a master's navigation commands fail while Chromium is
  stopped. The next one after the slideshow returns works.
- **Windows rejected in background mode** shows "Connecting…" until it times
  out (MS-MICE has no rejection message).

## Managing remembered senders

Settings → Screen Mirroring lists them, with **Forget**. Over SSH:

```bash
sudo slide-announcer-airshow list
sudo slide-announcer-airshow forget "Jane's iPhone"   # or an identifier, or all
journalctl _UID=$(id -u slideannouncer) | grep -i airshow
```

## To verify on hardware

- Image builds; measure rootfs growth (`ROOTFS_FIXED_SIZE_MB` is 5120; the
  test Pi's 2.9 GB filled with build deps, runtime deps should be far less).
- AirShow mode: Menu → Screen Mirroring → Start; approve S23 (Smart View),
  Windows (Win+K), iPhone; Home/Back returns to the slideshow; Power sleeps and
  wakes to the slideshow.
- Background mode: toggle on (kiosk restarts with AirShow behind), cast from a
  remembered device (slideshow stops, AirShow visible), disconnect (slideshow
  back in ~5 s plus Chromium start), forgotten device is rejected without a
  prompt.
- AirShow stops cleanly on a unit stop: no "previous run did not exit cleanly"
  on the next start.
- The hook's casting flag survives AirShow's 5 s `end` delay without flicker.
