"""open-presentation URL rewrite in the standalone revelation peer daemon:
the master builds URLs from its own origin (localhost:8000) and relies on the
peer to point them at the master's real address."""
import importlib
import importlib.util
import sys
import types
from importlib.machinery import SourceFileLoader
from pathlib import Path
from urllib.parse import quote

import pytest

SCRIPT = Path(__file__).resolve().parents[1] / "image/system/scripts/slide-announcer-revelation-peer"
# The daemon imports OS-image-only packages at module level; none are needed
# by the pure URL logic, so stub whichever are missing in a dev environment.
for _name in ("httpx", "socketio", "websocket", "zeroconf", "cryptography.exceptions",
              "cryptography.hazmat.primitives", "cryptography.hazmat.primitives.asymmetric"):
    try:
        importlib.import_module(_name)
    except ImportError:
        sys.modules[_name] = types.SimpleNamespace(
            ServiceBrowser=object, ServiceListener=object, Zeroconf=object, InvalidSignature=Exception,
            hashes=None, serialization=None, padding=None)

_loader = SourceFileLoader("revelation_peer_daemon", str(SCRIPT))
_spec = importlib.util.spec_from_loader(_loader.name, _loader)
peer = importlib.util.module_from_spec(_spec)
_loader.exec_module(peer)

MASTER = ("192.168.1.50", 8000)
PATH = "/presentations_k/slug/index.html?p=abc&remoteMultiplexId=u-1"


def rewrite(url, endpoint=MASTER, nat=False):
    return peer.rewrite_presentation_url(url, endpoint, nat)


def test_localhost_rewritten_to_master_host_and_port():
    assert rewrite("http://localhost:8000" + PATH) == "http://192.168.1.50:8000" + PATH
    assert rewrite("http://127.0.0.1:3000" + PATH) == "http://192.168.1.50:8000" + PATH


def test_private_url_already_matching_is_unchanged():
    url = "http://192.168.1.50:8000" + PATH
    assert rewrite(url) == url


def test_private_url_with_other_port_is_rewritten():
    assert rewrite("http://192.168.1.50:9000" + PATH) == "http://192.168.1.50:8000" + PATH


def test_nat_compatible_always_rewrites_private_hosts():
    url = "http://192.168.1.50:8000" + PATH
    assert rewrite(url, ("203.0.113.9", 8443), nat=True) == "http://203.0.113.9:8443" + PATH


def test_public_url_unchanged():
    url = "https://example.com/deck/index.html?p=1"
    assert rewrite(url) == url
    assert rewrite(url, nat=True) == url


def test_no_known_endpoint_unchanged():
    url = "http://localhost:8000" + PATH
    assert rewrite(url, endpoint=None) == url


def test_nested_src_rewritten_and_multiplex_id_kept():
    nested = "http://localhost:8000/presentations_k/slug/deck.md"
    url = f"http://localhost:8000/viewer.html?src={quote(nested, safe='')}&remoteMultiplexId=u-1"
    out = rewrite(url)
    expected = quote("http://192.168.1.50:8000/presentations_k/slug/deck.md", safe="")
    assert out == f"http://192.168.1.50:8000/viewer.html?src={expected}&remoteMultiplexId=u-1"


def test_ipv6_loopback_and_private_ranges():
    assert peer.is_private_or_local_host("::1")
    assert peer.is_private_or_local_host("[::1]")
    assert peer.is_private_or_local_host("10.1.2.3")
    assert peer.is_private_or_local_host("172.16.0.1") and peer.is_private_or_local_host("172.31.255.1")
    assert not peer.is_private_or_local_host("172.32.0.1")
    assert not peer.is_private_or_local_host("8.8.8.8")


@pytest.mark.parametrize("bad", [None, "", "not a url", "file:///etc/passwd", "javascript:alert(1)", "ftp://x/y"])
def test_invalid_urls_rejected(bad):
    assert rewrite(bad) is None


def test_handler_navigates_to_rewritten_url(monkeypatch):
    seen = []
    monkeypatch.setattr(peer, "cdp_navigate", seen.append)
    monkeypatch.setattr(peer, "read_display_settings", lambda: {})
    cmd = {"type": "open-presentation", "payload": {"url": "http://localhost:8000" + PATH}}
    peer.handle_peer_command(cmd, "192.168.1.50", 8000)
    assert seen == ["http://192.168.1.50:8000" + PATH]
    peer.handle_peer_command({"type": "open-presentation", "payload": {"url": "file:///x"}}, "192.168.1.50", 8000)
    assert len(seen) == 1
