"""Keeps /run/slide-announcer/update-hold current: the platform's updaters
(system/scripts/os-updater.py, updater/local_app_updater.py) won't restart the
kiosk or reboot the device while it exists (platform docs/DEVICE_CONTRACT.md,
"Update hold"). The platform defines only the file; what counts as something
that must not be interrupted is this product's call:

  video_stream    the LAN video receiver (SRT/RIST) is playing
  revelation      a Revelation presentation is on screen
  airshow_casting someone is screen-casting through AirShow
  play_once_video a video picked from a menu show hasn't finished
  live_channel    a live channel is on screen *and* someone has used the remote
                  since boot — a channel that is only up because it was pinned
                  when the device last shut down isn't worth waiting for

Ordinary announcement slides never hold an update. The first four can be seen
from the backend; the last two exist only in the kiosk page, which reports
them (POST /api/local/playback-state) every few seconds.
"""
import asyncio
import json
import os
import time
from pathlib import Path

from . import airshow, srt_stream_bridge

HOLD_FILE = Path("/run/slide-announcer/update-hold")
# Set once the kiosk page reports a key press; on tmpfs, so it clears at boot
# but survives a page reload or a backend restart in between.
INTERACTED_FLAG = Path("/run/slide-announcer/kiosk-interacted")
POLL_SECONDS = 5.0
# The kiosk page reports every few seconds. One that has gone quiet (the page
# was closed, Settings is open, Chromium died) is no longer showing anything.
REPORT_TTL_SECONDS = 30.0
# Stay held this long after the last reason clears, so an update doesn't land in
# the gap between two videos or cast sessions.
RELEASE_SECONDS = 60.0


def casting_flag() -> Path:
    # AirShow's session hook writes this as the same account the backend runs
    # as; the backend's service has no XDG_RUNTIME_DIR, so derive the default.
    runtime = os.environ.get("XDG_RUNTIME_DIR") or f"/run/user/{os.getuid()}"
    return Path(runtime) / "airshow-casting"


def airshow_casting() -> bool:
    try:
        return casting_flag().read_text().strip() == "start"
    except OSError:
        return False


class PlaybackReport:
    """The kiosk page's last word on what it is showing."""

    def __init__(self):
        self.play_once_video = False
        self.live_channel = False
        self.reported_at: float | None = None

    def update(self, now: float, *, play_once_video: bool, live_channel: bool) -> None:
        self.play_once_video, self.live_channel, self.reported_at = play_once_video, live_channel, now

    def current(self, now: float) -> tuple[bool, bool]:
        if self.reported_at is None or now - self.reported_at > REPORT_TTL_SECONDS:
            return False, False
        return self.play_once_video, self.live_channel


report = PlaybackReport()


def note_interaction(flag: Path | None = None) -> None:
    flag = flag or INTERACTED_FLAG
    try:
        flag.touch()
    except OSError as exc:
        print(f"[update-hold] can't record a key press at {flag}: {exc}", flush=True)


def interacted(flag: Path | None = None) -> bool:
    return (flag or INTERACTED_FLAG).exists()


def record_report(*, play_once_video: bool, live_channel: bool, interacted: bool, now: float | None = None) -> None:
    report.update(time.monotonic() if now is None else now, play_once_video=play_once_video, live_channel=live_channel)
    if interacted:
        note_interaction()


def current_reasons(now: float | None = None) -> list[str]:
    now = time.monotonic() if now is None else now
    reasons = []
    if srt_stream_bridge.is_playing():
        reasons.append("video_stream")
    if airshow.REVELATION_PRESENTING_FLAG.exists():
        reasons.append("revelation")
    if airshow_casting():
        reasons.append("airshow_casting")
    play_once_video, live_channel = report.current(now)
    if play_once_video:
        reasons.append("play_once_video")
    if live_channel and interacted():
        reasons.append("live_channel")
    return reasons


def write_hold(reasons: list[str], path: Path | None = None) -> None:
    """Rewritten on every poll while held: the updaters ignore a file nobody has
    touched for two minutes, so a dead backend can't block updates for good."""
    path = path or HOLD_FILE
    if not reasons:
        path.unlink(missing_ok=True)
        return
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text(json.dumps({"reasons": reasons}) + "\n")
    tmp.replace(path)


async def run_hold_monitor() -> None:
    """Background task (product.background_tasks)."""
    hold, published = airshow.Hold(release_after=RELEASE_SECONDS), None
    while True:
        try:
            reasons = hold.observe(time.monotonic(), current_reasons())
            write_hold(reasons)
            if reasons != published:
                if reasons or published:
                    state = f"holding updates for {', '.join(reasons)}" if reasons else "no longer holding updates"
                    print(f"[update-hold] {state}", flush=True)
                published = reasons
        except OSError as exc:
            print(f"[update-hold] can't update {HOLD_FILE}: {exc}", flush=True)
        await asyncio.sleep(POLL_SECONDS)
