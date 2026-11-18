"""channel_assist: clicks a paused JetStream player once its stream is ready,
hands focus back to the kiosk page, and leaves everything else alone."""
from products.slideannouncer import channel_assist as ca

PAGE = {"type": "page", "id": "P", "url": "http://localhost/kiosk"}
JET = {"type": "iframe", "id": "F", "url": "https://embed.jetstream.studio/embed/jsl:0sUSK6VA7GTNxQaY8E8L"}


class FakeCdp:
    def __init__(self, state):
        self.state = state
        self.log = []

    def attach(self, target_id):
        self.log.append(("attach", target_id))
        return f"s-{target_id}"

    def evaluate(self, session, expression):
        if expression is ca.PROBE:
            self.log.append(("probe", session))
            return self.state
        self.log.append(("refocus", session))
        return "BODY"

    def click(self, session, x, y):
        self.log.append(("click", session, x, y))

    def close(self):
        self.log.append(("close",))


def run(targets, state):
    cdp = FakeCdp(state)
    return ca.assist_once(get_json=lambda path: targets, cdp_factory=lambda: cdp), cdp.log


def test_a_paused_ready_player_is_clicked_then_focus_returns_to_the_kiosk():
    result, log = run([PAGE, JET], {"paused": True, "readyState": 4, "button": [34, 1046]})
    assert result == "clicked"
    assert log == [("attach", "F"), ("probe", "s-F"), ("click", "s-F", 34, 1046),
                   ("attach", "P"), ("refocus", "s-P"), ("close",)]


def test_a_playing_player_is_left_alone():
    result, log = run([PAGE, JET], {"paused": False, "readyState": 4, "button": [34, 1046]})
    assert result == "playing"
    assert not any(entry[0] == "click" for entry in log)


def test_nothing_happens_until_the_stream_is_ready():
    for state in (None, {"paused": True, "readyState": 1, "button": [1, 2]}, {"paused": True, "readyState": 4, "button": None}):
        result, log = run([PAGE, JET], state)
        assert result == "waiting"
        assert not any(entry[0] == "click" for entry in log)


def test_other_players_and_foreign_pages_are_never_touched():
    zype = {"type": "iframe", "id": "Z", "url": "https://player.zype.com/embed/69d0a21416d3588e4d7f9349.html"}
    lookalike = {"type": "iframe", "id": "L", "url": "https://embed.jetstream.studio.evil.example/embed/x"}
    foreign_page = {"type": "page", "id": "R", "url": "http://192.168.1.50:8000/presentation"}
    for targets in ([PAGE, zype], [PAGE, lookalike], [foreign_page, JET], []):
        result, log = run(targets, {"paused": True, "readyState": 4, "button": [1, 2]})
        assert result == "none" and log == []


def test_should_click_needs_all_three_conditions():
    assert ca.should_click({"paused": True, "readyState": 3, "button": [1, 2]})
    assert not ca.should_click({"paused": False, "readyState": 4, "button": [1, 2]})
    assert not ca.should_click({"paused": True, "readyState": 2, "button": [1, 2]})
    assert not ca.should_click({"paused": True, "readyState": 4})
    assert not ca.should_click(None)
