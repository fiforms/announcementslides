"""AirShow integration: the backend module and the device script's pure parts."""
import asyncio
import importlib.machinery
import importlib.util
import os
from pathlib import Path

import pytest

from products.slideannouncer import airshow

# run-tests.sh stages the backend elsewhere; the image/ tree is under PRODUCT_ROOT.
_ROOT = Path(os.environ.get("PRODUCT_ROOT") or Path(__file__).resolve().parents[1])
SCRIPT = _ROOT / "image/system/scripts/slide-announcer-airshow"


def _load_script():
    if not SCRIPT.exists():
        pytest.skip(f"{SCRIPT} not found (set PRODUCT_ROOT)")
    loader = importlib.machinery.SourceFileLoader("slide_announcer_airshow", str(SCRIPT))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


def test_parse_remembered_columns():
    out = (
        "AA:BB:CC:DD:EE:FF        Jane's iPhone (AirPlay)\n"
        "win-laptop-guid          Daniel (Lab)  PC (Miracast)\n"
        "bare-id\n"
        "cast-1234                Cast\n"
    )
    assert airshow.parse_remembered(out) == [
        {"id": "AA:BB:CC:DD:EE:FF", "name": "Jane's iPhone", "protocol": "AirPlay"},
        {"id": "win-laptop-guid", "name": "Daniel (Lab)  PC", "protocol": "Miracast"},
        {"id": "bare-id", "name": "", "protocol": ""},
        {"id": "cast-1234", "name": "", "protocol": "Cast"},
    ]


def test_parse_remembered_empty():
    assert airshow.parse_remembered("No remembered devices.\n") == []


def test_settings_round_trip_and_default(tmp_path):
    path = tmp_path / "airshow.json"
    assert airshow.read_settings(path) == {"background": False}
    assert airshow.write_settings({"background": True, "junk": 1}, path) == {"background": True}
    assert airshow.read_settings(path) == {"background": True}
    path.write_text("not json")
    assert airshow.read_settings(path) == {"background": False}


def test_forget_rejects_option_like_targets(monkeypatch):
    monkeypatch.setattr(airshow, "installed", lambda: True)
    with pytest.raises(airshow.AirShowError):
        asyncio.run(airshow.forget("--list-remembered"))


def test_set_background_restarts_kiosk_only_on_change(tmp_path, monkeypatch):
    calls = []

    async def fake_systemctl(*args):
        calls.append(args)

    monkeypatch.setattr(airshow, "SETTINGS_FILE", tmp_path / "airshow.json")
    monkeypatch.setattr(airshow, "installed", lambda: True)
    monkeypatch.setattr(airshow, "_systemctl", fake_systemctl)

    asyncio.run(airshow.set_background(True))
    asyncio.run(airshow.set_background(True))
    asyncio.run(airshow.set_background(False))
    assert calls == [("restart", "--no-block", airshow.KIOSK_UNIT)] * 2


def test_script_settings_match_backend(tmp_path):
    script = _load_script()
    path = tmp_path / "airshow.json"
    airshow.write_settings({"background": True}, path)
    assert script.read_settings(path) == {"background": True}
    assert script.CONFIG_HOME == Path(airshow.CONFIG_HOME)
    assert script.SETTINGS_FILE == airshow.SETTINGS_FILE


def test_script_hook_records_session(tmp_path, monkeypatch):
    script = _load_script()
    monkeypatch.setenv("XDG_RUNTIME_DIR", str(tmp_path))
    monkeypatch.setenv("AIRSHOW_DEVICE", "$(reboot)'; x")
    monkeypatch.setenv("AIRSHOW_SESSION", "start")
    assert script.hook() == 0
    assert script.is_casting()
    monkeypatch.setenv("AIRSHOW_SESSION", "end")
    assert script.hook() == 0
    assert not script.is_casting()
    monkeypatch.setenv("AIRSHOW_SESSION", "bogus")
    assert script.hook() == 2


def test_script_background_hooks_point_back_at_itself():
    script = _load_script()
    argv = script.background_argv("/usr/local/sbin/slide-announcer-airshow")
    assert argv[:3] == [script.AIRSHOW, "--approval", "known-only"]
    assert argv[argv.index("--on-session-start") + 1] == "/usr/local/sbin/slide-announcer-airshow hook"
    assert argv[argv.index("--on-session-end") + 1] == "/usr/local/sbin/slide-announcer-airshow hook"


def test_script_session_reopens_closed_stdin():
    """labwc starts the session with fd 0 closed; GStreamer's plugin scan breaks without it."""
    import subprocess
    import sys

    _load_script()  # skips when the image tree isn't available
    code = (
        "import importlib.machinery, importlib.util, os\n"
        f"loader = importlib.machinery.SourceFileLoader('m', {str(SCRIPT)!r})\n"
        "spec = importlib.util.spec_from_loader('m', loader)\n"
        "m = importlib.util.module_from_spec(spec); loader.exec_module(m)\n"
        "m.ensure_stdio()\n"
        "print(os.readlink('/proc/self/fd/0'))\n"
    )
    out = subprocess.run([sys.executable, "-c", code], stdin=subprocess.DEVNULL, capture_output=True, text=True,
                         preexec_fn=lambda: os.close(0), check=True).stdout.strip()
    assert out == os.devnull


def test_script_prepare_registry_skips_background_when_off(tmp_path, monkeypatch):
    script = _load_script()
    calls = []
    monkeypatch.setattr(script, "SETTINGS_FILE", tmp_path / "airshow.json")
    monkeypatch.setattr(script.os, "access", lambda *_: True)
    monkeypatch.setattr(script.subprocess, "run", lambda argv, **kw: calls.append((argv, kw)) or
                        script.subprocess.CompletedProcess(argv, 0, "", ""))
    assert script.prepare_registry("background") == 0
    assert calls == []
    script.SETTINGS_FILE.write_text('{"background": true}')
    assert script.prepare_registry("background") == 0
    argv, kw = calls[0]
    assert argv == [script.AIRSHOW, "--help"]
    assert kw["env"]["QT_QPA_PLATFORM"] == "offscreen"
    assert kw["env"]["XDG_CONFIG_HOME"] == str(script.CONFIG_HOME)
