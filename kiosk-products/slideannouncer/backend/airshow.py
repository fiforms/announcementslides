"""AirShow screen mirroring: the local app's side (docs/AIRSHOW.md).

The device side lives in image/system/: slide-announcer-airshow.service (AirShow
mode, attended) and the slide-announcer-airshow script, which also runs the
kiosk session (background mode). This module only:
  - stores the feature switch (Settings > Features) and the background-mode
    switch, restarting the kiosk when background mirroring turns on or off,
  - starts AirShow mode (the unit's Conflicts= stops the slideshow),
  - keeps HOLD_FILE up to date: background mirroring pauses while an SRT/RIST
    stream or a Revelation presentation is on screen,
  - lists and forgets remembered senders through AirShow's own CLI.
"""
import asyncio
import json
import os
import re
import time
from pathlib import Path

from . import srt_stream_bridge

AIRSHOW = "/usr/bin/airshow"
AIRSHOW_UNIT = "slide-announcer-airshow.service"
KIOSK_UNIT = "slide-announcer-kiosk.service"
# Must match image/system/scripts/slide-announcer-airshow.
CONFIG_HOME = "/data/airshow"
SETTINGS_FILE = Path("/data/status/airshow.json")
# Read by the session wrapper: present (JSON {"reasons": [...]}) while
# background mirroring should be paused. /run/slide-announcer is
# root:slideannouncer 0775 (slide-announcer-power-button-dirs.service).
HOLD_FILE = Path("/run/slide-announcer/airshow-hold")
# Written by the Revelation peer daemon while a presentation is on screen.
REVELATION_PRESENTING_FLAG = Path("/run/slide-announcer/revelation-presenting")
CLI_TIMEOUT_SECONDS = 10
HOLD_POLL_SECONDS = 1.0
# Stay paused this long after the last stream/presentation ends: a pause ends
# with AirShow and then Chromium restarting, so don't do that between two
# streams a few seconds apart.
HOLD_RELEASE_SECONDS = 30.0

# "<identifier>  <name> (<protocol>)" — the description is optional and is
# separated by two or more spaces (AirShow pads identifiers into a column).
_LIST_LINE = re.compile(r"^(?P<id>.*?)(?:\s{2,}(?P<what>.+))?$")
_WHAT = re.compile(r"^(?P<name>.*?)\s*\((?P<protocol>[^()]*)\)$")


class AirShowError(Exception):
    pass


def installed() -> bool:
    return os.access(AIRSHOW, os.X_OK)


def _clean(data: dict) -> dict:
    return {"enabled": data.get("enabled") is True, "background": data.get("background") is True}


def read_settings(path: Path | None = None) -> dict:
    try:
        data = json.loads((path or SETTINGS_FILE).read_text())
    except (OSError, ValueError):
        data = {}
    return _clean(data if isinstance(data, dict) else {})


def background_active(settings: dict) -> bool:
    """Background mirroring runs only while the feature itself is on."""
    return settings["enabled"] and settings["background"]


def write_settings(settings: dict, path: Path | None = None) -> dict:
    path = path or SETTINGS_FILE
    clean = _clean(settings)
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text(json.dumps(clean) + "\n")
    tmp.replace(path)
    return clean


def parse_remembered(output: str) -> list[dict]:
    devices = []
    for line in output.splitlines():
        line = line.rstrip()
        if not line or line == "No remembered devices.":
            continue
        match = _LIST_LINE.match(line)
        ident, what = match["id"].strip(), (match["what"] or "").strip()
        name, protocol = what, ""
        described = _WHAT.match(what)
        if described:
            name, protocol = described["name"], described["protocol"]
        elif what in ("AirPlay", "Cast", "Miracast", "DLNA", "AirShow"):
            name, protocol = "", what
        devices.append({"id": ident, "name": name, "protocol": protocol})
    return devices


async def _run(*argv: str, env: dict | None = None) -> tuple[int, str, str]:
    proc = await asyncio.create_subprocess_exec(
        *argv, env=env, stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
    )
    try:
        out, err = await asyncio.wait_for(proc.communicate(), CLI_TIMEOUT_SECONDS)
    except asyncio.TimeoutError as exc:
        proc.kill()
        await proc.wait()
        raise AirShowError(f"{argv[0]} timed out") from exc
    return proc.returncode, out.decode(errors="replace"), err.decode(errors="replace")


def _cli_env() -> dict:
    env = dict(os.environ)
    env["XDG_CONFIG_HOME"] = CONFIG_HOME  # these commands need no display
    return env


async def list_remembered() -> list[dict]:
    if not installed():
        return []
    code, out, err = await _run(AIRSHOW, "--list-remembered", env=_cli_env())
    if code != 0:
        raise AirShowError(err.strip() or f"airshow --list-remembered failed (exit {code})")
    return parse_remembered(out)


async def forget(target: str) -> None:
    if not installed():
        raise AirShowError("AirShow is not installed")
    if not target or target.startswith("-"):
        raise AirShowError("invalid device")
    code, _out, err = await _run(AIRSHOW, f"--forget-remembered={target}", env=_cli_env())
    if code != 0:
        raise AirShowError(err.strip() or f"airshow --forget-remembered failed (exit {code})")


async def _systemctl(*args: str) -> None:
    # Polkit grants this account start/restart on slide-announcer-*.service
    # (system/polkit/50-slide-announcer-system.rules in the platform).
    code, _out, err = await _run("systemctl", *args)
    if code != 0:
        raise AirShowError(err.strip() or f"systemctl {' '.join(args)} failed (exit {code})")


async def start_mode() -> None:
    """AirShow mode. --no-block: starting it stops the kiosk (Conflicts=), and
    with it the browser making this request."""
    if not installed():
        raise AirShowError("AirShow is not installed")
    if not read_settings()["enabled"]:
        raise AirShowError("Screen Mirroring is turned off (Settings > Features)")
    await _systemctl("start", "--no-block", AIRSHOW_UNIT)


async def _update(**changes) -> dict:
    """Save, and restart the kiosk if background mirroring turned on or off:
    the kiosk session reads it when it starts."""
    previous = read_settings()
    settings = write_settings({**previous, **changes})
    if background_active(settings) != background_active(previous) and installed():
        await _systemctl("restart", "--no-block", KIOSK_UNIT)
    return settings


async def set_enabled(enabled: bool) -> dict:
    return await _update(enabled=enabled)


async def set_background(enabled: bool) -> dict:
    return await _update(background=enabled)


def read_hold(path: Path | None = None) -> list[str]:
    try:
        reasons = json.loads((path or HOLD_FILE).read_text()).get("reasons", [])
    except (OSError, ValueError, AttributeError):
        return []
    return [r for r in reasons if isinstance(r, str)]


async def status() -> dict:
    settings = read_settings()
    return {
        "installed": installed(),
        **settings,
        "paused_for": read_hold() if background_active(settings) else [],
    }


# --- Pausing background mirroring -------------------------------------------

def current_hold_reasons() -> list[str]:
    reasons = []
    if srt_stream_bridge.is_playing():
        reasons.append("video_stream")
    if REVELATION_PRESENTING_FLAG.exists():
        reasons.append("revelation")
    return reasons


class Hold:
    """Turns a reason list sampled every poll into the hold to publish: on at
    once, off only after `release_after` seconds with no reason."""

    def __init__(self, release_after: float = HOLD_RELEASE_SECONDS):
        self.release_after = release_after
        self._reasons: list[str] = []
        self._last_seen: float | None = None

    def observe(self, now: float, reasons: list[str]) -> list[str]:
        if reasons:
            self._reasons, self._last_seen = reasons, now
        elif self._last_seen is not None and now - self._last_seen >= self.release_after:
            self._reasons, self._last_seen = [], None
        return self._reasons


def write_hold(reasons: list[str], path: Path | None = None) -> None:
    path = path or HOLD_FILE
    if not reasons:
        path.unlink(missing_ok=True)
        return
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text(json.dumps({"reasons": reasons}) + "\n")
    tmp.replace(path)


async def run_hold_monitor() -> None:
    """Background task (product.background_tasks): keep HOLD_FILE current."""
    hold, published = Hold(), None
    while True:
        try:
            reasons = hold.observe(time.monotonic(), current_hold_reasons())
            if reasons != published:
                write_hold(reasons)
                if reasons or published:
                    state = f"paused for {', '.join(reasons)}" if reasons else "resumed"
                    print(f"[airshow] background screen mirroring {state}", flush=True)
                published = reasons
        except OSError as exc:
            print(f"[airshow] can't update {HOLD_FILE}: {exc}", flush=True)
        await asyncio.sleep(HOLD_POLL_SECONDS)
