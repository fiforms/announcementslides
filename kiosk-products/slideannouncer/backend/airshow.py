"""AirShow screen mirroring: the local app's side (docs/AIRSHOW.md).

The device side lives in image/system/: slide-announcer-airshow.service (AirShow
mode, attended) and the slide-announcer-airshow script, which also runs the
kiosk session (background mode). This module only:
  - starts AirShow mode (the unit's Conflicts= stops the slideshow),
  - stores the background-mode switch and restarts the kiosk to apply it,
  - lists and forgets remembered senders through AirShow's own CLI.
"""
import asyncio
import json
import os
import re
from pathlib import Path

AIRSHOW = "/usr/bin/airshow"
AIRSHOW_UNIT = "slide-announcer-airshow.service"
KIOSK_UNIT = "slide-announcer-kiosk.service"
# Must match image/system/scripts/slide-announcer-airshow.
CONFIG_HOME = "/data/airshow"
SETTINGS_FILE = Path("/data/status/airshow.json")
CLI_TIMEOUT_SECONDS = 10

# "<identifier>  <name> (<protocol>)" — the description is optional and is
# separated by two or more spaces (AirShow pads identifiers into a column).
_LIST_LINE = re.compile(r"^(?P<id>.*?)(?:\s{2,}(?P<what>.+))?$")
_WHAT = re.compile(r"^(?P<name>.*?)\s*\((?P<protocol>[^()]*)\)$")


class AirShowError(Exception):
    pass


def installed() -> bool:
    return os.access(AIRSHOW, os.X_OK)


def read_settings(path: Path | None = None) -> dict:
    try:
        data = json.loads((path or SETTINGS_FILE).read_text())
    except (OSError, ValueError):
        data = {}
    return {"background": data.get("background") is True}


def write_settings(settings: dict, path: Path | None = None) -> dict:
    path = path or SETTINGS_FILE
    clean = {"background": settings.get("background") is True}
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
    await _systemctl("start", "--no-block", AIRSHOW_UNIT)


async def set_background(enabled: bool) -> dict:
    """The kiosk session reads the switch when it starts, so restart it."""
    previous = read_settings()
    settings = write_settings({**previous, "background": enabled})
    if settings != previous and installed():
        await _systemctl("restart", "--no-block", KIOSK_UNIT)
    return settings


async def status() -> dict:
    return {"installed": installed(), **read_settings()}
