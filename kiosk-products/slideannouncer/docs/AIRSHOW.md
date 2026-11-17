# AirShow screen mirroring

The Slide Announcer receives screen mirroring through
[AirShow](https://github.com/fiforms/AirShow): AirPlay (iPhone, iPad, Mac),
Miracast (Windows Win+K, Android Smart View over Wi-Fi Direct), Google Cast
(including GNOME Network Displays), and DLNA (untested). AirShow's own kiosk
reference is `docs/session-hooks.md` in that repo, installed on the device as
`/usr/share/doc/airshow/session-hooks.md`.

**Status:** tested on a Raspberry Pi 4B and working: AirShow mode, background
mode, casting, approvals and quit keys. (`0.4.7_0.3.0` is the image version,
platform_product. The AirShow release itself is whatever `image/debs.lock` pins,
currently `v0.1.0+fiforms1`.) AirShow was also tested earlier on a separate
throwaway Pi image: see *What the handoff tested* below. *To verify on hardware*
is the checklist to rerun after changes to the units, wrapper or AirShow
version.

## Where state lives

| What | Where | Survives reboot |
|---|---|---|
| Remembered senders (AirShow's QSettings) | `/data/airshow/AirShow/AirShow.conf` | yes |
| Feature and background switches | `/data/status/airshow.json` | yes |
| Pause for streams/presentations | `/run/slide-announcer/airshow-hold` (JSON, written by the backend) | no |
| Revelation presenting flag | `/run/slide-announcer/revelation-presenting` (written by the peer daemon) | no |
| "A sender is casting" flag | `$XDG_RUNTIME_DIR/airshow-casting` (`start`/`end`, written by the hook) | no |
| Display-sleep marker | `/run/slide-announcer/kiosk-sleeping` | no |
| GStreamer registry | `~slideannouncer/.cache/gstreamer-1.0` (tmpfs) | no, rebuilt by `prepare-registry` |

Don't point `XDG_CONFIG_HOME` anywhere else for `airshow` by hand: running it
as the kiosk user without `XDG_CONFIG_HOME=/data/airshow` reads or writes a
different (volatile) list. Use `slide-announcer-airshow list`/`forget`, which set
it.

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

## What to expect when casting

Behaviour of AirShow itself (from the handoff), worth knowing before calling
something a bug:

- **Discovery is slow.** Windows lists the Pi in Win+K about 20 s after Win+K
  opens. The Wi-Fi Direct beacon listens 200 ms of every 2 s, because longer
  listening made mDNS unreliable on the Pi's single radio. While a session is on
  screen the beacon pauses (the receiver can't be discovered then) and resumes
  5 s after.
- **Resolution and rate.** Miracast is offered at 720p at most (software
  decoding; the Pi can't keep up with 1080p). Expect about 30 fps from phones and
  Windows.
- **Windows' approval window is short.** Windows gives up within a few seconds,
  so a slow first approval fails and the sender has to try again. Once
  remembered, later connections don't prompt. Approve new Windows senders
  promptly, with the remote in keyboard mode.
- **The approval dialog and the remote.** With the remote in keyboard mode,
  Enter picks the highlighted button (Allow is first), Left/Right/Tab switch,
  Esc denies. In mouse mode the OK button is a mouse click instead.
- **No PIN for Windows or Android.** A Windows PIN needs MS-MICE DTLS
  encryption; an Android PIN needs NetworkManager 1.60 (Debian 13 has 1.52).
  AirPlay has its own PIN option, which this integration doesn't set. The
  Settings PIN only gates *approving* (AirShow mode). Once a sender is
  remembered, anyone with that device in range can cast in background mode, so
  *Forget* senders you no longer trust.
- **Rejected senders** (background mode, unknown device): only logged. No hook
  runs and the screen isn't touched. Windows shows "Connecting…" until it times
  out.
- **`AIRSHOW_DEVICE` is sender-chosen text.** The hook only logs it (as a quoted
  repr). Anything else that uses the hook's environment must never evaluate it.

## What the handoff tested

On a throwaway Pi 4 image (Debian 13, labwc, NetworkManager, built-in Wi-Fi),
with AirShow run directly, not through these units:

- Samsung S23 Ultra (Smart View): approve, stream, disconnect, reconnect with no
  prompt.
- Windows 11 Win+K: approve, stream, video with no tearing, disconnect, reconnect
  with no prompt.
- GNOME Network Displays: the `slideannouncer-… (network)` entry.
- Remote Home/Back with `--quit-keys 172,158` exits AirShow.
- `--approval known-only`: a forgotten Windows laptop is rejected with no
  prompt; a remembered phone connects directly.
- The next session doesn't show the previous session's last frame.

iPhone/AirPlay and DLNA were not part of that run.

**On this image** (Pi 4B, `image/debs.lock`'s AirShow): Windows 11, Samsung
Android and GNOME Network Displays (the Network Displays client) are tested and
work. iPhone (AirPlay) worked on an older AirShow release but hasn't been
retested with the current one. DLNA is untested.

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

The `.deb` also installs a udev rule
(`/usr/lib/udev/rules.d/60-airshow-wifi-direct.rules`) that turns off Wi-Fi power
saving on the Wi-Fi Direct interfaces only. The NetworkManager drop-in above
covers the station connection, so both are needed.

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
- **Escape hatch / kiosk guard:** both talk to Chromium over CDP. The guard
  (`slide-announcer-kiosk-guard`) is *not* enabled for this product (`image/enable`
  lists only the Revelation peer), so today only the escape hatch matters. With
  Chromium stopped (AirShow mode, or casting in background mode), an
  escape-hatch trigger fails to navigate, so it restarts the kiosk unit. Starting
  the kiosk stops the AirShow-mode unit (`Conflicts=`), which ends AirShow mode
  or, in background mode, restarts the whole session and so ends the cast. That's
  the intended way out. If the guard is ever enabled for this product, it has to
  stay quiet while Chromium is down: its rescue path restarts the kiosk unit,
  which would end AirShow mode.
- **Unit stops and `ExecStopPost`:** `return-to-kiosk` runs on every stop of the
  AirShow-mode unit, including the one caused by an escape-hatch restart of the
  kiosk. Starting a unit that is already starting is harmless.
- **Stop timeouts.** AirShow restores wpa_supplicant's Wi-Fi Direct settings on
  SIGTERM, which takes a moment. The AirShow-mode unit has `TimeoutStopSec=15`.
  The kiosk unit's is 10 (the handoff's minimum). systemd signals Chromium and
  AirShow together, so the wrapper's own 8 s waits don't add up, but don't lower
  the kiosk's timeout. A kill shows as "a previous run did not exit cleanly" in
  AirShow's log on the next start. That's harmless (it recovers the settings),
  but it means the stop wasn't graceful.
- **Revelation peer:** a master's navigation commands fail while Chromium is
  stopped. The next one after the slideshow returns works.
- **Windows rejected in background mode** shows "Connecting…" until it times
  out (MS-MICE has no rejection message).
- **Switching the feature off while AirShow mode is running** doesn't stop it
  (only background mirroring is restarted away). Leave with Home/Back.
- **Session wrapper env.** The AirShow-mode unit sets `KIOSK_SESSION_WRAPPER`
  with `Environment=`, which `kiosk-start.sh` lets win over `product.env`'s
  background wrapper. That's why the same script serves both units.

## Managing remembered senders

Settings → Features → Screen Mirroring lists them, with **Forget**. Over SSH:

```bash
sudo slide-announcer-airshow list
sudo slide-announcer-airshow forget "Jane's iPhone"   # or an identifier, or all
journalctl _UID=$(id -u slideannouncer) | grep -i airshow
```

`forget` takes the identifier or name that `list` prints. Quote names with
spaces. Names and identifiers come from the senders, so treat them as untrusted.

## Troubleshooting

- **Logs.** `journalctl _UID=$(id -u slideannouncer)` has AirShow's output, with
  frame rate and buffer levels every 5 s while streaming. The wrapper's lines are
  prefixed `slide-announcer-airshow:`, the backend's `[airshow]`.
- **Flickers, then the slideshow comes back:** the GStreamer registry problem
  above (`Missing GStreamer plugin 'appsink'`). `slide-announcer-airshow
  prepare-registry` output is in the unit's journal.
- **Streams arrive in bursts, "connection unstable":** Wi-Fi power saving is on.
  Check `/etc/NetworkManager/conf.d/90-wifi-powersave-off.conf` exists.
- **Android connects, then fails with a permission error:** polkit's
  `org.freedesktop.NetworkManager.*` rule for `slideannouncer`, and the unit's
  `PAMName=login` (active local session).
- **Android drops the link:** NetworkManager removes Wi-Fi Direct groups it didn't
  create, so don't mark `p2p-dev-wlan0` unmanaged.
- **Wi-Fi Direct (Win+K, Android) causes trouble generally:** AirShow's
  `--no-beacon` turns both off. Nothing here passes it; add it to
  `attended()` and `background_argv()` in `system/scripts/slide-announcer-airshow`
  to try it.
- **Out of disk:** the runtime packages are small next to AirShow's build
  dependencies, but the rootfs growth hasn't been measured (below).

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
- Remembered senders survive a reboot (`/data/airshow`), and
  `slide-announcer-airshow list` shows the same ones the Settings page does.
- An iPhone (AirPlay) on the current AirShow release (only an older one was
  tested) and DLNA (untested).
- Casting during AirShow mode's own screen: the next session doesn't show the
  previous one's last frame.
- AirShow stops cleanly on a unit stop (both units): no "previous run did not
  exit cleanly" on the next start. Check the kiosk unit's 10 s timeout
  especially.
- Approving a new Windows sender in AirShow mode with the remote fits inside
  Windows' short approval window.
- The hook's casting flag survives AirShow's 5 s `end` delay without flicker.
