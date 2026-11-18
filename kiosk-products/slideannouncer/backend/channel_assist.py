"""JetStream play assist — starts a live channel whose embedded player won't.

Hope Channel's JetStream player loads its stream but never starts it (its
`autoplay` comes from the broadcaster's own player configuration, not
anything the embed URL can set), so on a kiosk it sits paused forever. This
task watches over Chrome's DevTools port (the same one kiosk-navigate,
captive_portal and the Revelation peer use) and, when a JetStream iframe in
the kiosk page is paused with its stream ready, sends it one simulated
mouse click on its own play button — a user gesture, not injected code. It
also restarts a player that paused itself after a stall.

Deliberately narrow:
  - only iframes whose URL starts with JETSTREAM_PREFIX, and only inside
    the kiosk page (http://localhost);
  - the only thing read from their page is the video's paused/ready state
    and the play button's position;
  - after clicking, keyboard focus goes straight back to the kiosk page, or
    the remote's Menu/Back/Home keys would go to their page instead
    (ShowFrameLayer.vue guards the same thing from the frontend side);
  - clicks that don't start playback back off instead of repeating forever.

An iframe channel is unmounted while the show is suspended (paused, or an
SRT feed on screen), so there is nothing here to undo a deliberate pause.
"""
import asyncio
import json

import httpx
import websocket

CDP_PORT = 9222
KIOSK_ORIGIN = "http://localhost"
JETSTREAM_PREFIX = "https://embed.jetstream.studio/embed/"
INTERVAL_SECONDS = 10
MAX_ATTEMPTS = 3
BACKOFF_SECONDS = 300
HAVE_FUTURE_DATA = 3

# Read-only: the video's state and the play button's centre, found through
# the player's shadow DOM. Returns null until the player has a <video>.
PROBE = r"""
(() => {
  let video = null, button = null;
  const walk = (root) => {
    for (const el of root.querySelectorAll('*')) {
      if (!video && el.tagName === 'VIDEO') video = el;
      if (!button && el.tagName === 'MEDIA-PLAY-BUTTON') button = el;
      if (el.shadowRoot) walk(el.shadowRoot);
    }
  };
  walk(document);
  if (!video) return null;
  let centre = null;
  if (button) {
    const r = button.getBoundingClientRect();
    if (r.width > 0 && r.height > 0) centre = [r.x + r.width / 2, r.y + r.height / 2];
  }
  return { paused: video.paused, readyState: video.readyState, button: centre };
})()
"""

# On the kiosk page: if an iframe holds focus, give it back to the page.
REFOCUS = r"""
(() => {
  const el = document.activeElement;
  if (el && el.tagName === 'IFRAME') el.blur();
  window.focus();
  return document.activeElement ? document.activeElement.tagName : null;
})()
"""


def should_click(state) -> bool:
    """Paused with enough data to play, and a visible play button to press."""
    return bool(state) and state.get("paused") is True \
        and (state.get("readyState") or 0) >= HAVE_FUTURE_DATA and bool(state.get("button"))


def _get_json(path: str):
    with httpx.Client(timeout=5) as client:
        return client.get(f"http://127.0.0.1:{CDP_PORT}{path}").json()


class Cdp:
    """A browser-level DevTools connection with flattened target sessions."""

    def __init__(self, get_json=_get_json, connect=websocket.create_connection):
        self._ws = connect(get_json("/json/version")["webSocketDebuggerUrl"], timeout=5)
        self._next = 0

    def call(self, method: str, params: dict | None = None, session: str | None = None) -> dict:
        self._next += 1
        msg = {"id": self._next, "method": method, "params": params or {}}
        if session:
            msg["sessionId"] = session
        self._ws.send(json.dumps(msg))
        for _ in range(200):  # skip unrelated events
            reply = json.loads(self._ws.recv())
            if reply.get("id") == self._next:
                if "error" in reply:
                    raise RuntimeError(reply["error"])
                return reply.get("result", {})
        raise RuntimeError(f"no reply to {method}")

    def attach(self, target_id: str) -> str:
        return self.call("Target.attachToTarget", {"targetId": target_id, "flatten": True})["sessionId"]

    def evaluate(self, session: str, expression: str):
        result = self.call("Runtime.evaluate", {"expression": expression, "returnByValue": True}, session)
        return result.get("result", {}).get("value")

    def click(self, session: str, x: float, y: float) -> None:
        for kind in ("mouseMoved", "mousePressed", "mouseReleased"):
            self.call("Input.dispatchMouseEvent", {
                "type": kind, "x": x, "y": y, "clickCount": 1,
                "button": "none" if kind == "mouseMoved" else "left",
                "buttons": 1 if kind == "mousePressed" else 0,
            }, session)

    def close(self) -> None:
        self._ws.close()


def assist_once(get_json=_get_json, cdp_factory=Cdp) -> str:
    """One check. Returns 'none' (no JetStream player showing), 'waiting'
    (not ready yet), 'playing', or 'clicked'."""
    targets = get_json("/json")
    page = next((t for t in targets if t.get("type") == "page" and t.get("url", "").startswith(KIOSK_ORIGIN)), None)
    frame = next((t for t in targets if t.get("type") == "iframe" and t.get("url", "").startswith(JETSTREAM_PREFIX)), None)
    if not page or not frame:
        return "none"

    cdp = cdp_factory()
    try:
        frame_session = cdp.attach(frame["id"])
        state = cdp.evaluate(frame_session, PROBE)
        if not should_click(state):
            return "playing" if state and state.get("paused") is False else "waiting"
        cdp.click(frame_session, *state["button"])
        cdp.evaluate(cdp.attach(page["id"]), REFOCUS)
        return "clicked"
    finally:
        cdp.close()


async def run_forever() -> None:
    attempts = 0
    while True:
        await asyncio.sleep(INTERVAL_SECONDS)
        try:
            result = await asyncio.to_thread(assist_once)
        except Exception:  # noqa: BLE001 - kiosk restarting / debug port briefly down; try again next round
            continue
        if result == "clicked":
            attempts += 1
            if attempts >= MAX_ATTEMPTS:
                print(f"[channel-assist] {attempts} clicks didn't start the JetStream player; backing off", flush=True)
                await asyncio.sleep(BACKOFF_SECONDS)
                attempts = 0
        elif result in ("playing", "none"):
            attempts = 0
