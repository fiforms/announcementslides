"""What holds the platform's updaters off (update_hold.py): the backend's own
signals plus the kiosk page's playback report."""
import json

import pytest

from products.slideannouncer import update_hold


@pytest.fixture
def quiet(tmp_path, monkeypatch):
    """Nothing playing, with every file the module reads redirected to tmp_path."""
    monkeypatch.setattr(update_hold.airshow, "REVELATION_PRESENTING_FLAG", tmp_path / "revelation-presenting")
    monkeypatch.setattr(update_hold.srt_stream_bridge, "is_playing", lambda: False)
    monkeypatch.setattr(update_hold, "casting_flag", lambda: tmp_path / "airshow-casting")
    monkeypatch.setattr(update_hold, "INTERACTED_FLAG", tmp_path / "kiosk-interacted")
    monkeypatch.setattr(update_hold, "HOLD_FILE", tmp_path / "update-hold")
    monkeypatch.setattr(update_hold, "report", update_hold.PlaybackReport())
    return tmp_path


def page(now=0.0, **state):
    update_hold.record_report(**{"play_once_video": False, "live_channel": False, "interacted": False, **state}, now=now)


def test_ordinary_announcements_hold_nothing(quiet):
    page()
    assert update_hold.current_reasons(now=1.0) == []


def test_the_lan_video_receiver_holds(quiet, monkeypatch):
    monkeypatch.setattr(update_hold.srt_stream_bridge, "is_playing", lambda: True)
    assert update_hold.current_reasons() == ["video_stream"]


def test_a_revelation_presentation_holds(quiet):
    (quiet / "revelation-presenting").write_text("1")
    assert update_hold.current_reasons() == ["revelation"]


@pytest.mark.parametrize("content, held", [("start", True), ("start\n", True), ("end", False), ("", False)])
def test_airshow_casting_holds_only_while_the_flag_says_start(quiet, content, held):
    (quiet / "airshow-casting").write_text(content)
    assert (update_hold.current_reasons() == ["airshow_casting"]) is held


def test_a_cast_with_no_flag_file_holds_nothing(quiet):
    assert update_hold.current_reasons() == []


def test_an_unfinished_menu_video_holds(quiet):
    page(play_once_video=True)
    assert update_hold.current_reasons(now=1.0) == ["play_once_video"]


def test_a_finished_menu_video_stops_holding(quiet):
    page(now=0, play_once_video=True)
    page(now=5, play_once_video=False)
    assert update_hold.current_reasons(now=6.0) == []


def test_a_live_channel_nobody_chose_does_not_hold(quiet):
    # Up only because it was pinned when the device last shut down.
    page(live_channel=True)
    assert update_hold.current_reasons(now=1.0) == []


def test_a_live_channel_holds_once_someone_has_used_the_remote(quiet):
    page(live_channel=True, interacted=True)
    assert update_hold.current_reasons(now=1.0) == ["live_channel"]


def test_interaction_outlives_a_page_reload(quiet):
    page(live_channel=True, interacted=True)
    # The reloaded page hasn't seen a key yet and says so, but the flag is on tmpfs.
    page(now=10, live_channel=True, interacted=False)
    assert update_hold.current_reasons(now=11.0) == ["live_channel"]


def test_a_halted_stream_stops_holding(quiet):
    page(live_channel=True, interacted=True)
    page(now=5, live_channel=False, interacted=True)  # the 5 h idle interlock stopped it
    assert update_hold.current_reasons(now=6.0) == []


def test_a_silent_page_is_not_playing_anything(quiet):
    page(now=0, play_once_video=True, live_channel=True, interacted=True)
    assert update_hold.current_reasons(now=update_hold.REPORT_TTL_SECONDS - 1) == ["play_once_video", "live_channel"]
    assert update_hold.current_reasons(now=update_hold.REPORT_TTL_SECONDS + 1) == []


def test_no_report_yet_holds_nothing(quiet):
    assert update_hold.current_reasons() == []


def test_reasons_combine(quiet, monkeypatch):
    monkeypatch.setattr(update_hold.srt_stream_bridge, "is_playing", lambda: True)
    (quiet / "airshow-casting").write_text("start")
    page(play_once_video=True)
    assert update_hold.current_reasons(now=1.0) == ["video_stream", "airshow_casting", "play_once_video"]


def test_hold_file_round_trip_and_removal(quiet):
    update_hold.write_hold(["airshow_casting", "video_stream"])
    assert json.loads(update_hold.HOLD_FILE.read_text()) == {"reasons": ["airshow_casting", "video_stream"]}
    update_hold.write_hold([])
    assert not update_hold.HOLD_FILE.exists()
    update_hold.write_hold([])  # removing what isn't there is fine


def test_the_hold_lingers_between_two_videos():
    hold = update_hold.airshow.Hold(release_after=update_hold.RELEASE_SECONDS)
    assert hold.observe(0, ["play_once_video"]) == ["play_once_video"]
    assert hold.observe(30, []) == ["play_once_video"]
    assert hold.observe(update_hold.RELEASE_SECONDS, []) == []


def test_the_playback_endpoint_feeds_the_report(quiet):
    from fastapi import FastAPI
    from fastapi.testclient import TestClient

    from products.slideannouncer import routes

    app = FastAPI()
    app.include_router(routes.router)
    reply = TestClient(app).post("/api/local/playback-state", json={"play_once_video": True, "live_channel": True, "interacted": True})
    assert reply.json() == {"ok": True}
    assert update_hold.interacted()
    assert update_hold.report.play_once_video and update_hold.report.live_channel
