"""Tests for a show's frame in sync.py — caching its two files and what the
active playlist hands the kiosk. No network: the server is an
httpx.MockTransport, and every /data path is redirected into tmp_path.
"""
import asyncio

import httpx
import pytest

from products.slideannouncer import sync

SERVER = "https://slides.example.org"


@pytest.fixture(autouse=True)
def isolated(tmp_path, monkeypatch):
    monkeypatch.setattr(sync, "MEDIA_DIR", tmp_path / "media")
    monkeypatch.setattr(sync.widgets, "read_index", lambda: {"clock": {"version": "1.0.0", "entry": "widget.js"}})
    return tmp_path


def frame(base="bg.jpg", mime="image/jpeg", overlay="ov.svg", widgets=()):
    return {
        "file_url": f"{SERVER}/storage/slides/{base}" if base else None,
        "mime_type": mime if base else None,
        "overlay_url": f"{SERVER}/storage/slides/{overlay}" if overlay else None,
        "overlay_mime_type": "image/svg+xml" if overlay else None,
        "overlay_media_id": 7 if overlay else None,
        "widgets": list(widgets),
    }


def run_sync(show_id, new, previous=None, handler=None):
    handler = handler or (lambda request: httpx.Response(200, content=request.url.path.encode()))

    async def go():
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            return await sync._sync_frame(client, show_id, new, previous)
    return asyncio.run(go())


def test_both_files_are_cached_under_show_keyed_names():
    entry = run_sync("3", frame())

    assert entry["base_local_filename"] == "show-3-base.jpg"
    assert entry["overlay_local_filename"] == "show-3-overlay.svg"
    assert (sync.MEDIA_DIR / "show-3-base.jpg").read_text() == "/storage/slides/bg.jpg"
    assert entry["overlay_media_id"] == 7


def test_an_unchanged_frame_is_not_downloaded_again():
    entry = run_sync("3", frame())
    calls = []

    def handler(request):
        calls.append(request.url.path)
        return httpx.Response(200, content=b"x")

    assert run_sync("3", frame(), entry, handler) == entry
    assert calls == []


def test_a_replaced_background_swaps_the_cached_file():
    entry = run_sync("3", frame())
    new = run_sync("3", frame(base="bg.mp4", mime="video/mp4"), entry)

    assert new["base_local_filename"] == "show-3-base.mp4"
    assert not (sync.MEDIA_DIR / "show-3-base.jpg").exists()
    assert (sync.MEDIA_DIR / "show-3-base.mp4").exists()


def test_a_removed_part_or_frame_deletes_its_file():
    entry = run_sync("3", frame())
    entry = run_sync("3", frame(overlay=None), entry)
    assert entry["overlay_local_filename"] is None
    assert not (sync.MEDIA_DIR / "show-3-overlay.svg").exists()

    assert run_sync("3", None, entry) is None
    assert not (sync.MEDIA_DIR / "show-3-base.jpg").exists()


def test_a_failed_download_keeps_the_cached_file_and_retries():
    entry = run_sync("3", frame())

    def failing(request):
        return httpx.Response(500)

    kept = run_sync("3", frame(base="bg2.jpg"), entry, failing)
    assert kept["base_local_filename"] == "show-3-base.jpg"
    assert (sync.MEDIA_DIR / "show-3-base.jpg").exists()
    assert kept["base_url"] == frame(base="bg2.jpg")["file_url"]  # still differs, so the next cycle retries


def manifest_with(slides, frame_entry):
    return {"3": {"name": "Main", "is_main": True, "slides": slides, "frame": frame_entry}}


def slide(sid, mime):
    (sync.MEDIA_DIR).mkdir(parents=True, exist_ok=True)
    (sync.MEDIA_DIR / f"{sid}.bin").write_text("x")
    return {"id": sid, "local_filename": f"{sid}.bin", "mime_type": mime}


def test_the_playlist_frame_lists_only_parts_on_disk_with_local_widget_urls():
    entry = run_sync("3", frame(widgets=[{"id": "as-el-1", "widget": "clock"}, {"id": "as-el-2", "widget": "gone"}]))
    playlist = sync._build_active_playlist(manifest_with({"1": slide("1", "image/jpeg")}, entry))

    out = playlist[0]["frame"]
    assert out["media_url"] == "/media/show-3-base.jpg"
    assert out["overlay_media_url"] == "/media/show-3-overlay.svg"
    assert [w["id"] for w in out["widgets"]] == ["as-el-1"]  # the second has no mirrored bundle
    assert out["widgets"][0]["data_url"] == "/api/local/widget-data/7/as-el-1/__endpoint__"

    (sync.MEDIA_DIR / "show-3-base.jpg").unlink()
    assert sync._build_active_playlist(manifest_with({}, entry))[0]["frame"]["media_url"] is None


def test_no_frame_means_none():
    assert sync._build_active_playlist(manifest_with({"1": slide("1", "image/jpeg")}, None))[0]["frame"] is None


def test_a_background_video_drops_video_slides_from_the_playlist():
    entry = run_sync("3", frame(base="bg.mp4", mime="video/mp4"))
    slides = {"1": slide("1", "image/jpeg"), "2": slide("2", "video/mp4")}

    playlist = sync._build_active_playlist(manifest_with(slides, entry))

    assert [s["id"] for s in playlist[0]["slides"]] == ["1"]
