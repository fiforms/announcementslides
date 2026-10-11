"""RIST caller mode (rist_caller): the device connects out to a sender that
is itself the RIST listener."""
import pytest

from products.slideannouncer import srt_sink


@pytest.fixture(autouse=True)
def sink_file(tmp_path, monkeypatch):
    monkeypatch.setattr(srt_sink, "SRT_SINK_FILE", tmp_path / "srt-sink.json")
    monkeypatch.setattr(srt_sink, "rist_supported", lambda: True)


def _caller_config(**overrides):
    srt_sink.update_settings({
        "mode": "rist_caller", "sender_host": "obs-pc.local", "sender_port": 5000,
        "sender_passphrase": "SenderPass01", **overrides,
    })
    srt_sink.set_local_enabled(True)
    return srt_sink.read_config()


def test_caller_input_args_connect_out_without_listen_marker():
    args = srt_sink.listener_input_args(_caller_config())
    assert args[-2:] == ["-i", "rist://obs-pc.local:5000"]
    assert "@" not in args[-1]
    assert args[args.index("-secret") + 1] == "SenderPass01"
    assert args[args.index("-analyzeduration") + 1] == str(srt_sink.CALLER_PROBE_MS * 1000)


def test_caller_needs_host_and_passphrase_to_be_enabled():
    config = _caller_config()
    assert srt_sink.effective_enabled(config)
    assert not srt_sink.effective_enabled({**config, "sender_host": ""})
    assert not srt_sink.effective_enabled({**config, "sender_passphrase": ""})


def test_caller_requires_rist_support(monkeypatch):
    config = _caller_config()
    monkeypatch.setattr(srt_sink, "rist_supported", lambda: False)
    assert not srt_sink.effective_enabled(config)


@pytest.mark.parametrize("host", ["", "bad host", "obs?x=1", "a/b", "-leading.dash", "x" * 300])
def test_rejects_bad_sender_host(host):
    with pytest.raises(srt_sink.SrtSinkConfigError):
        srt_sink.update_settings({"sender_host": host})


@pytest.mark.parametrize("host", ["192.168.1.20", "obs-pc.local", "OBS"])
def test_accepts_hostname_or_ipv4(host):
    assert srt_sink.update_settings({"sender_host": host})["sender_host"] == host


def test_rejects_odd_sender_port():
    with pytest.raises(srt_sink.SrtSinkConfigError):
        srt_sink.update_settings({"sender_port": 5001})


def test_no_sender_url_in_caller_mode():
    assert srt_sink.sender_url("pi", _caller_config()) is None


def test_caller_fields_sync_from_server():
    srt_sink.apply_server_config({
        "revision": 3, "mode": "rist_caller", "sender_host": "10.0.0.5",
        "sender_port": 6000, "sender_passphrase": "SenderPass01",
    })
    config = srt_sink.read_config()
    assert (config["mode"], config["sender_host"], config["sender_port"]) == ("rist_caller", "10.0.0.5", 6000)
    assert srt_sink.report()["sender_host"] == "10.0.0.5"
