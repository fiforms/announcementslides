"""Tests for slides with no image in sync.py: a slide may be overlay/widgets
only (or empty). It must not break a sync cycle, and the playlist keeps it
only if there is something to show. No network: the server is an
httpx.MockTransport; every /data path is redirected into tmp_path.
"""
import asyncio

import httpx
import pytest

import pairing
from products.slideannouncer import sync

SERVER = "https://slides.example.org"
REAL_ASYNC_CLIENT = httpx.AsyncClient


@pytest.fixture(autouse=True)
def isolated(tmp_path, monkeypatch):
    monkeypatch.setattr(sync, "MEDIA_DIR", tmp_path / "media")
    monkeypatch.setattr(sync, "MANIFEST_FILE", tmp_path / "manifest.json")
    monkeypatch.setattr(sync, "SETTINGS_FILE", tmp_path / "settings.json")
    monkeypatch.setattr(sync, "PLAYLIST_FILE", tmp_path / "active-playlist.json")
    monkeypatch.setattr(sync, "LOCATION_FILE", tmp_path / "location.json")
    monkeypatch.setattr(sync, "STATUS_FILE", tmp_path / "status.json")
    monkeypatch.setattr(sync.widgets, "read_index", lambda: {"clock": {"version": "1.0.0", "entry": "widget.js"}})
    monkeypatch.setattr(sync.widgets, "mirror", _noop_mirror)
    monkeypatch.setattr(sync.srt_sink, "apply_server_config", lambda cfg: None)
    monkeypatch.setattr(pairing, "read_device_token", lambda: "tok")
    monkeypatch.setattr(pairing, "read_server_url", lambda: SERVER)
    monkeypatch.setattr(pairing, "api_url", lambda path: f"{SERVER}/api/slide-announcers/{path}")
    monkeypatch.setattr(sync.pinning, "read_pinned_show_id", lambda: None)
    return tmp_path


async def _noop_mirror(client, bundles, server_url):
    return {}


def slide(sid, **kw):
    base = {"id": sid, "language": None, "file_url": None, "mime_type": None, "video_playback_mode": None, "video_muted": False,
            "overlay_url": None, "overlay_mime_type": None, "overlay_media_id": None, "widgets": [], "expires_at": None}
    return {**base, **kw}


def run_cycle(monkeypatch, slides):
    def handler(request):
        if request.url.path.endswith("/shows"):
            return httpx.Response(200, json={"shows": [{"id": "3", "name": "Main", "is_main": True, "slides": slides, "frame": None}],
                                             "widgets": [], "location": None, "settings": {}})
        return httpx.Response(200, content=b"x")

    monkeypatch.setattr(httpx, "AsyncClient", lambda **kw: REAL_ASYNC_CLIENT(transport=httpx.MockTransport(handler), **kw))
    asyncio.run(sync.sync_once())
    return sync.read_shows()[0]["slides"]


def test_a_sync_with_image_less_slides_completes_and_keeps_only_those_with_something_to_show(monkeypatch):
    overlay_only = slide(2, overlay_url=f"{SERVER}/storage/slides/o.svg", overlay_mime_type="image/svg+xml", overlay_media_id=9)
    widgets_only = slide(3, overlay_url=f"{SERVER}/storage/slides/w.svg", overlay_mime_type="image/svg+xml", overlay_media_id=10,
                         widgets=[{"id": "as-el-1", "widget": "clock"}])
    nothing = slide(4)
    normal = slide(1, file_url=f"{SERVER}/storage/slides/a.jpg", mime_type="image/jpeg")

    played = run_cycle(monkeypatch, [normal, overlay_only, widgets_only, nothing])

    assert [s["id"] for s in played] == ["1", "2", "3"]
    by_id = {s["id"]: s for s in played}
    assert by_id["1"]["media_url"] == "/media/1.jpg"
    assert by_id["2"]["media_url"] is None and by_id["2"]["mime_type"] is None
    assert by_id["2"]["overlay_media_url"] == "/media/2-overlay.svg"
    assert by_id["3"]["widgets"][0]["data_url"] == "/api/local/widget-data/10/as-el-1/__endpoint__"


def test_removing_a_slides_image_deletes_its_cached_file_but_keeps_the_slide(monkeypatch):
    with_image = slide(1, file_url=f"{SERVER}/storage/slides/a.jpg", mime_type="image/jpeg",
                       overlay_url=f"{SERVER}/storage/slides/o.svg", overlay_mime_type="image/svg+xml", overlay_media_id=9)
    run_cycle(monkeypatch, [with_image])
    assert (sync.MEDIA_DIR / "1.jpg").exists()

    played = run_cycle(monkeypatch, [{**with_image, "file_url": None, "mime_type": None}])

    assert not (sync.MEDIA_DIR / "1.jpg").exists()
    assert [s["id"] for s in played] == ["1"]
    assert played[0]["media_url"] is None and played[0]["overlay_media_url"] == "/media/1-overlay.svg"


def test_a_slide_that_loses_everything_drops_out_of_the_playlist(monkeypatch):
    overlay_only = slide(2, overlay_url=f"{SERVER}/storage/slides/o.svg", overlay_mime_type="image/svg+xml", overlay_media_id=9)
    assert [s["id"] for s in run_cycle(monkeypatch, [overlay_only])] == ["2"]

    assert run_cycle(monkeypatch, [slide(2)]) == []


CHANNEL = {"kind": "hls", "url": "https://3abn.tulixcdn.com/3abn2/3abn_live/smil:3abn_live.smil/playlist.m3u8", "muted": False}


def test_a_live_channel_from_the_catalog_syncs_as_a_slide_less_show_and_stays_pinnable(monkeypatch):
    channel_show = {"id": "channel-7", "name": "3ABN", "is_main": False, "is_channel": True, "slides": [],
                    "frame": {"file_url": None, "mime_type": None, "youtube_url": None, "channel": CHANNEL,
                              "overlay_url": None, "overlay_mime_type": None, "overlay_media_id": None, "widgets": []}}

    def handler(request):
        if request.url.path.endswith("/shows"):
            return httpx.Response(200, json={"shows": [{"id": "3", "name": "Main", "is_main": True, "slides": [], "frame": None}, channel_show],
                                             "widgets": [], "location": None, "settings": {}})
        return httpx.Response(200, content=b"x")

    cleared = []
    monkeypatch.setattr(sync.pinning, "read_pinned_show_id", lambda: "channel-7")
    monkeypatch.setattr(sync.pinning, "write_pinned_show_id", cleared.append)
    monkeypatch.setattr(httpx, "AsyncClient", lambda **kw: REAL_ASYNC_CLIENT(transport=httpx.MockTransport(handler), **kw))
    asyncio.run(sync.sync_once())

    shows = {s["id"]: s for s in sync.read_shows()}
    assert shows["channel-7"]["is_channel"] is True
    assert shows["channel-7"]["slides"] == []
    assert shows["channel-7"]["frame"]["channel"] == CHANNEL
    assert shows["3"]["is_channel"] is False
    assert cleared == []  # still offered, so the pin stays


def test_a_muted_library_video_keeps_its_flag_so_the_player_mutes_it(monkeypatch):
    silent = slide(1, file_url=f"{SERVER}/storage/library/bg/aa/a.mp4", mime_type="video/mp4", video_muted=True)
    own = slide(2, file_url=f"{SERVER}/storage/slides/b.mp4", mime_type="video/mp4")

    played = {s["id"]: s for s in run_cycle(monkeypatch, [silent, own])}

    assert played["1"]["video_muted"] is True
    assert played["2"]["video_muted"] is False
