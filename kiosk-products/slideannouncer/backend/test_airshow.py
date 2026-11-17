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
    off = {"enabled": False, "background": False}
    assert airshow.read_settings(path) == off
    assert airshow.write_settings({"background": True, "junk": 1}, path) == {"enabled": False, "background": True}
    assert airshow.read_settings(path) == {"enabled": False, "background": True}
    path.write_text("not json")
    assert airshow.read_settings(path) == off
    path.write_text("[1, 2]")
    assert airshow.read_settings(path) == off


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

    restart = ("restart", "--no-block", airshow.KIOSK_UNIT)
    asyncio.run(airshow.set_background(True))  # feature still off: nothing runs yet
    assert calls == []
    asyncio.run(airshow.set_enabled(True))  # now background mirroring starts
    asyncio.run(airshow.set_background(True))
    assert calls == [restart]
    asyncio.run(airshow.set_enabled(False))  # and stops with the feature
    asyncio.run(airshow.set_enabled(True))
    asyncio.run(airshow.set_background(False))
    assert calls == [restart] * 4


def test_script_settings_match_backend(tmp_path):
    script = _load_script()
    path = tmp_path / "airshow.json"
    airshow.write_settings({"enabled": True, "background": True}, path)
    assert script.read_settings(path) == {"enabled": True, "background": True}
    assert script.HOLD_FILE == airshow.HOLD_FILE
    assert script.REVELATION_PRESENTING_FLAG == airshow.REVELATION_PRESENTING_FLAG
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
    script.SETTINGS_FILE.write_text('{"background": true}')  # feature off
    assert script.prepare_registry("background") == 0
    assert calls == []
    script.SETTINGS_FILE.write_text('{"enabled": true, "background": true}')
    assert script.prepare_registry("background") == 0
    argv, kw = calls[0]
    assert argv == [script.AIRSHOW, "--help"]
    assert kw["env"]["QT_QPA_PLATFORM"] == "offscreen"
    assert kw["env"]["XDG_CONFIG_HOME"] == str(script.CONFIG_HOME)


def test_start_mode_refused_while_feature_off(tmp_path, monkeypatch):
    monkeypatch.setattr(airshow, "SETTINGS_FILE", tmp_path / "airshow.json")
    monkeypatch.setattr(airshow, "installed", lambda: True)
    with pytest.raises(airshow.AirShowError):
        asyncio.run(airshow.start_mode())


def test_hold_on_at_once_off_after_release_delay():
    hold = airshow.Hold(release_after=30)
    assert hold.observe(0, []) == []
    assert hold.observe(1, ["video_stream"]) == ["video_stream"]
    assert hold.observe(20, []) == ["video_stream"]  # a short gap keeps it paused
    assert hold.observe(25, ["revelation"]) == ["revelation"]
    assert hold.observe(54, []) == ["revelation"]
    assert hold.observe(55, []) == []


def test_hold_file_round_trip(tmp_path):
    path = tmp_path / "airshow-hold"
    airshow.write_hold(["video_stream", "revelation"], path)
    assert airshow.read_hold(path) == ["video_stream", "revelation"]
    airshow.write_hold([], path)
    assert not path.exists()
    assert airshow.read_hold(path) == []


def test_current_hold_reasons(tmp_path, monkeypatch):
    flag = tmp_path / "revelation-presenting"
    monkeypatch.setattr(airshow, "REVELATION_PRESENTING_FLAG", flag)
    monkeypatch.setattr(airshow.srt_stream_bridge, "is_playing", lambda: False)
    assert airshow.current_hold_reasons() == []
    flag.write_text("1")
    monkeypatch.setattr(airshow.srt_stream_bridge, "is_playing", lambda: True)
    assert airshow.current_hold_reasons() == ["video_stream", "revelation"]


def test_status_reports_pause_only_while_background_runs(tmp_path, monkeypatch):
    monkeypatch.setattr(airshow, "SETTINGS_FILE", tmp_path / "airshow.json")
    monkeypatch.setattr(airshow, "HOLD_FILE", tmp_path / "airshow-hold")
    monkeypatch.setattr(airshow, "installed", lambda: True)
    airshow.write_hold(["video_stream"])
    airshow.write_settings({"enabled": False, "background": True})
    assert asyncio.run(airshow.status())["paused_for"] == []
    airshow.write_settings({"enabled": True, "background": True})
    assert asyncio.run(airshow.status()) == {
        "installed": True, "enabled": True, "background": True, "paused_for": ["video_stream"]}
