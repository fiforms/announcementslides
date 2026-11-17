# AirShow screen mirroring

The Slide Announcer receives screen mirroring through
[AirShow](https://github.com/fiforms/AirShow): AirPlay (iPhone, iPad, Mac),
Miracast (Windows Win+K, Android Smart View over Wi-Fi Direct), Google Cast
(including GNOME Network Displays), and DLNA (untested). AirShow's own kiosk
reference is `docs/session-hooks.md` in that repo, installed on the device as
`/usr/share/doc/airshow/session-hooks.md`.

**Status:** running on a Pi (`0.4.7_0.3.0` plus the registry fix below): AirShow
mode and background mode start. Casting, approvals and quit keys have not been
tested end to end yet; see *To verify on hardware* below.

## Switching it on

Settings → **Features** (after Advanced) has the optional features' switches:
**Screen Mirroring** at the top, then Revelation Peering and LAN Video
Receiver. Each switched-on feature gets its own page under Features in the
rail. With Screen Mirroring on:

- the Menu gets **Screen Mirroring**, which starts AirShow mode. When a
  Settings PIN is set, it asks for the PIN first: AirShow mode is where new
  senders are approved, and an approved sender can then cast in background mode
  with nobody present. The Menu item opens `/settings/airshow?start=1`, so the
  router's existing Settings PIN gate applies. The page starts AirShow when it
  opens, and leaving Settings locks it again as usual. Remembered senders
  casting in background mode never involve the PIN;
- Settings → Features → Screen Mirroring has **Start AirShow**, the background
  switch, and the remembered senders.

Turning the feature off hides both, stops background mirroring, and makes the
backend refuse to start AirShow mode. The settings are kept for when it's turned
back on.

## The two modes

| | AirShow mode (attended) | Background mode (unattended) |
|---|---|---|
| Started by | Menu → **Screen Mirroring**, or Settings → Features → Screen Mirroring → **Start AirShow** | Settings → Features → Screen Mirroring → *Allow mirroring during the slideshow* |
| Unit | `slide-announcer-airshow.service` (`Conflicts=` the kiosk) | inside `slide-announcer-kiosk.service` |
| New senders | Allow/Deny prompt, answered with the remote (Enter allows) | rejected silently (`--approval known-only`) |
| Leaves by | Home or Back on the remote (`--quit-keys 172,158`) | the sender disconnecting (≈5 s later) |

Both modes share one settings file, `/data/airshow/AirShow/AirShow.conf`
(`XDG_CONFIG_HOME=/data/airshow`), so a sender approved in AirShow mode is
admitted in background mode. It has to live on `/data`, because the kiosk
user's home is on the tmpfs `/var` overlay. Both switches are
`/data/status/airshow.json` (`{"enabled": …, "background": …}`); background
mirroring runs only when both are on.

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
Turning background mirroring on or off (either switch) restarts the kiosk unit
to apply it.

### Pausing for streams and presentations

AirShow's Wi-Fi Direct listening (200 ms of every 2 s, off channel on the Pi's
single radio) costs an SRT/RIST stream or a Revelation presentation dropped and
late packets. AirShow can't turn that off while running, so background mirroring
stops AirShow while either one is on screen:

- **Signals.** The local app (`backend/airshow.py`, `run_hold_monitor`) checks
  every second for `srt_stream_bridge.is_playing()` (that covers RIST pushes
  from a Revelation master too) and `/run/slide-announcer/revelation-presenting`.
  The peer daemon sets that flag on `open-presentation` and clears it on
  `close-presentation` and at its own start; the session wrapper also clears it
  whenever it starts Chromium, which always opens `/kiosk`.
- **The hold.** While there's a reason, the backend writes
  `/run/slide-announcer/airshow-hold` with `{"reasons": [...]}`. It removes the
  file only after 30 s with no reason, so back-to-back streams don't cause a
  stop and start between them.
- **What the wrapper does.** While the hold exists, the wrapper stops AirShow
  (Chromium keeps playing). A cast already on screen is left to finish first.
  When the hold goes, it starts AirShow again and then restarts Chromium,
  because AirShow's window maps on top. So the end of a pause shows AirShow's
  idle screen briefly, then the slideshow reloads. A labwc window rule keeping
  `app_id=airshow` at the bottom could avoid the restart, but it hasn't been
  tried.
- **On screen.** Settings → Features → Screen Mirroring shows "Paused: …" with
  the reason. AirShow mode (attended) isn't affected: starting it is a
  deliberate choice.

## Image pieces

| Path (`image/`) | What |
|---|---|
| `debs.lock` | pinned AirShow release `.deb` (URL + sha256), fetched by `build.sh` |
| `packages` | AirShow's Recommends, listed explicitly: `qt6-wayland` (without it there is no window under labwc), `iw`, `gstreamer1.0-pipewire` |
| `rootfs/etc/NetworkManager/conf.d/90-wifi-powersave-off.conf` | Wi-Fi power saving off for the station connection (bursts, "connection unstable", and half the Wi-Fi Direct link rate with it on) |
| `system/slide-announcer-airshow.service` | AirShow mode |
| `rootfs/etc/systemd/system/slide-announcer-kiosk.service.d/50-airshow.conf` | kiosk `ExecStartPre`: GStreamer registry for background mode (below) |
| `system/scripts/slide-announcer-airshow` | session wrapper (both modes), hook, return-to-kiosk, `list`/`forget` |
| `product.env` | `KIOSK_SESSION_WRAPPER`, `KIOSK_SCREEN_UNITS` (platform seams, `slideannouncer/docs/PRODUCTS.md`) |

Installing AirShow needs a full image or RAUC bundle (new apt packages). Later
changes to the units, script and drop-in can go out as hotfixes.

Permissions are already in place on the platform. `slideannouncer` is in
`netdev` (wpa_supplicant D-Bus), polkit grants it every
`org.freedesktop.NetworkManager.*` action (Android Miracast's temporary
`wifi-p2p` connection) and start/stop of `slide-announcer-*.service`, and both
units have `PAMName=login` (an active local session). NetworkManager keeps
managing `p2p-dev-wlan0`; don't mark it unmanaged.

## GStreamer registry (found on hardware)

If AirShow does its own first GStreamer plugin scan inside the labwc session,
`gst-plugin-scanner` dies after a plugin or two. The half-built registry
(about 15 of 272 plugins, in the kiosk user's tmpfs `~/.cache/gstreamer-1.0`)
is saved, and every start until reboot aborts with
`Missing GStreamer plugin 'appsink'`. That's the "flickers, then the slideshow
comes back" symptom. The same scan from a plain unit, SSH or `systemd-run`
works; the trigger inside labwc isn't pinned down. With a complete registry,
AirShow under labwc only validates it and starts fine.

So both units run `slide-announcer-airshow prepare-registry` as
`ExecStartPre`, outside labwc. It runs `airshow --help` with
`QT_QPA_PLATFORM=offscreen`, which does `gst_init` and AirShow's plugin check
and exits (about 1.7 s on the first start of a boot, 0.2 s after). For the
kiosk it does nothing unless background mirroring is on. Verified on
`0.4.7_0.3.0`: 3 of 3 cold AirShow-mode starts, plus a cold background start.

Separately, labwc starts the session command with stdin closed, and AirShow
started with fd 0 closed fails the same way (`airshow 0<&-`). The session
wrapper reopens `/dev/null` on fds 0 to 2.

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

Settings → Features → Screen Mirroring lists them, with **Forget**. Over SSH:

```bash
sudo slide-announcer-airshow list
sudo slide-announcer-airshow forget "Jane's iPhone"   # or an identifier, or all
journalctl _UID=$(id -u slideannouncer) | grep -i airshow
```

## To verify on hardware

- Image builds; measure rootfs growth (`ROOTFS_FIXED_SIZE_MB` is 5120; the
  test Pi's 2.9 GB filled with build deps, runtime deps should be far less).
- Settings → Features: the switches; Screen Mirroring's page and Menu entry
  appear only while it's on.
- AirShow mode: Menu → Screen Mirroring; approve S23 (Smart View),
  Windows (Win+K), iPhone; Home/Back returns to the slideshow; Power sleeps and
  wakes to the slideshow.
- Background mode: toggle on (kiosk restarts with AirShow behind), cast from a
  remembered device (slideshow stops, AirShow visible), disconnect (slideshow
  back in ~5 s plus Chromium start), forgotten device is rejected without a
  prompt.
- Pausing: with background on, start an SRT/RIST stream or a Revelation
  presentation (AirShow stops, and the stream has no drops from Wi-Fi Direct);
  end it (about 30 s later AirShow starts again and the slideshow reloads).
- AirShow stops cleanly on a unit stop: no "previous run did not exit cleanly"
  on the next start.
- The hook's casting flag survives AirShow's 5 s `end` delay without flicker.
