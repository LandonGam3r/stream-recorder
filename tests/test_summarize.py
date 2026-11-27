"""Automated tests for the PC summary tool (no network, no extra software).

Run from the repository folder:  python3 -m unittest discover -s tests -p "test_*.py"
"""
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "pc-tools" / "summarize"))
import summarize as s  # noqa: E402


class Captions(unittest.TestCase):
    def test_json3(self):
        data = json.dumps({"events": [{"tStartMs": 1000, "dDurationMs": 2000, "segs": [{"utf8": "hello"}, {"utf8": " there"}]},
                                      {"tStartMs": 3000, "dDurationMs": 1000, "segs": [{"utf8": "\n"}]}]})
        self.assertEqual(s.parse_captions(data, "json3"), [(1.0, 3.0, "hello there")])

    def test_vtt_rolling_repeats_dropped(self):
        vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nhello <c>world</c>\n\n00:00:02.000 --> 00:00:03.000\nworld\n\n00:00:03.000 --> 00:00:04.500\nnext line\n"
        self.assertEqual([t for _, _, t in s.parse_captions(vtt, "vtt")], ["hello world", "next line"])

    def test_pick_track_prefers_manual_in_own_language(self):
        info = {"language": "de", "subtitles": {"de": [{"ext": "vtt", "url": "m-de"}], "live_chat": [{"ext": "json", "url": "c"}]},
                "automatic_captions": {"de-orig": [{"ext": "json3", "url": "a-de"}]}}
        self.assertEqual(s.pick_caption_track(info), ("de", "vtt", "m-de", True))

    def test_pick_track_auto_original(self):
        info = {"language": "nl", "automatic_captions": {"en": [{"ext": "vtt", "url": "a-en"}], "nl-orig": [{"ext": "json3", "url": "a-nl"}]}}
        self.assertEqual(s.pick_caption_track(info), ("nl", "json3", "a-nl", False))

    def test_no_track(self):
        self.assertIsNone(s.pick_caption_track({"subtitles": {"live_chat": [{"ext": "json", "url": "c"}]}}))


class RecorderChat(unittest.TestCase):
    def test_times_follow_the_timeline(self):
        # Video part 2 starts at 100 s video time, 10:10:00 wall clock (an ad break of 8 min is not in the video).
        meta = {"video_start": "2026-11-27 10:00:00.000", "timeline": [[0, "2026-11-27 10:00:00.000"], [100, "2026-11-27 10:10:00.000"]]}
        text = "#META {}\n[2026-11-27 10:00:30] a: one\n[2026-11-27 10:05:00] b: during the ad\n[2026-11-27 10:10:05] ** c subscribed\n"
        chat, _ = s.recorder_chat(text, meta)
        self.assertEqual(chat[0], (30.0, "a: one"))
        self.assertEqual(chat[1][0], 100.0)  # held at the start of the next part
        self.assertEqual(chat[2], (105.0, "(notice) c subscribed"))


class Money(unittest.TestCase):
    def test_cost_tiers(self):
        for model, m in s.MODELS.items():
            n = m["tier"] or 1_000_000  # the lower price applies up to the tier
            self.assertAlmostEqual(s.cost(model, n, 0), round(n * m["price"][0][0] / 1e6, 4), places=4)
            self.assertAlmostEqual(s.cost(model, 0, 1000), round(1000 * m["price"][0][1] / 1e6, 4), places=4)
            if m["tier"]:
                self.assertAlmostEqual(s.cost(model, m["tier"] + 1, 0), round((m["tier"] + 1) * m["price"][1][0] / 1e6, 4), places=4)


class Pictures(unittest.TestCase):
    def test_frame_times(self):
        self.assertEqual(s.frame_times(20, []), [])
        t = s.frame_times(4 * 3600, [(1610, "x")])
        self.assertLessEqual(len(t), s.MAX_FRAMES)
        self.assertEqual(t, sorted(t))
        self.assertTrue(all(b - a >= 60 for a, b in zip(t, t[1:])))
        self.assertIn(1700, t)  # a picture shortly after each chapter start


class Names(unittest.TestCase):
    def test_clock_and_safe_name(self):
        self.assertEqual(s.clock(3725), "1:02:05")
        name = s.safe_name('a/b:c*?"<>| d')
        self.assertFalse(any(ch in name for ch in '/\\:*?"<>|'))


if __name__ == "__main__":
    unittest.main()
