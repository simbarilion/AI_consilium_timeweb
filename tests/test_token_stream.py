import json
import unittest
from types import SimpleNamespace
from unittest.mock import patch

from backend.app import app, extract_text_delta, sse


FINAL_JSON = {
    "title": "Решение",
    "summary": "Краткое резюме",
    "mvp": ["A"],
    "stack": "Python",
    "roadmap": ["1"],
    "risks": ["R"],
    "design": "UX",
    "next_steps": ["N"],
}


def fake_stream(_system: str, _user: str):
    yield "Привет, "
    yield "мир"


def parse_sse(text: str) -> list[dict]:
    events = []
    for block in text.split("\n\n"):
        if not block.strip():
            continue
        event_name = "message"
        data_lines = []
        for line in block.split("\n"):
            if line.startswith("event:"):
                event_name = line[6:].strip()
            if line.startswith("data:"):
                data_lines.append(line[5:].lstrip())
        if not data_lines:
            continue
        events.append({
            "event": event_name,
            "data": json.loads("\n".join(data_lines)),
        })
    return events


class ExtractTextDeltaTests(unittest.TestCase):
    def test_reads_responses_api_output_text_delta(self):
        event = SimpleNamespace(type="response.output_text.delta", delta="токен")
        self.assertEqual(extract_text_delta(event), "токен")

    def test_reads_dict_shaped_delta_event(self):
        event = {"type": "response.output_text.delta", "delta": "ещё"}
        self.assertEqual(extract_text_delta(event), "ещё")

    def test_ignores_non_text_events(self):
        event = SimpleNamespace(type="response.created", delta=None)
        self.assertEqual(extract_text_delta(event), "")

    def test_empty_delta_is_ignored(self):
        event = SimpleNamespace(type="response.output_text.delta", delta="")
        self.assertEqual(extract_text_delta(event), "")

    def test_sse_helper_keeps_event_name_and_json_payload(self):
        payload = sse("agent_token", {"agent": "product", "delta": "Hi"})
        self.assertIn("event: agent_token\n", payload)
        self.assertIn('"delta": "Hi"', payload)


class ConsiliumTokenStreamTests(unittest.TestCase):
    def setUp(self):
        self.client = app.test_client()

    def test_consilium_emits_agent_tokens_before_agent_done(self):
        with patch("backend.app.configured", return_value=True), \
             patch("backend.app.stream_llm", side_effect=fake_stream), \
             patch("backend.app.call_llm", return_value=json.dumps(FINAL_JSON)):
            response = self.client.post(
                "/api/consilium",
                json={"idea": "Веб-приложение для совместных поездок"},
            )
            body = response.get_data(as_text=True)
            status = response.status_code
            mimetype = response.mimetype

        self.assertEqual(status, 200)
        self.assertTrue(mimetype.startswith("text/event-stream"))
        events = parse_sse(body)
        names = [item["event"] for item in events]

        self.assertIn("agent_token", names)
        self.assertIn("agent_done", names)
        self.assertLess(names.index("agent_token"), names.index("agent_done"))

        first_agent_tokens = []
        for item in events:
            if item["event"] == "agent_done":
                break
            if item["event"] == "agent_token":
                first_agent_tokens.append(item["data"]["delta"])

        self.assertEqual(first_agent_tokens, ["Привет, ", "мир"])
        first_done = next(item for item in events if item["event"] == "agent_done")
        self.assertEqual(first_done["data"]["text"], "Привет, мир")
        self.assertEqual(first_done["data"]["agent"], "product")


if __name__ == "__main__":
    unittest.main()
