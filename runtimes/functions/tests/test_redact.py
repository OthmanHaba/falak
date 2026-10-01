"""PYTHONPATH=runtimes/functions/python python3 -m unittest discover -s runtimes/functions/tests"""

import json
import pathlib
import unittest
from urllib.parse import urlsplit

from kiln_fn.redact import redact_path, redact_text, safe_url

CASES = json.loads((pathlib.Path(__file__).parent / "redact-cases.json").read_text())


class RedactTest(unittest.TestCase):
    def test_paths(self) -> None:
        for given, want in CASES["paths"]:
            self.assertEqual(redact_path(given), want, given)

    def test_urls(self) -> None:
        for given, want in CASES["urls"]:
            u = urlsplit(given)
            self.assertEqual(safe_url(u.scheme, u.hostname or "", u.port, u.path), want, given)

    def test_texts(self) -> None:
        for given, want in CASES["texts"]:
            self.assertEqual(redact_text(given), want, given)

    def test_requests_urls_drop_userinfo(self) -> None:
        from kiln_fn import telemetry

        u = telemetry._Url("https://user:pass@api.example.com:8443/bot1:AAEhBP0av28X5mJBEdcJfZc3K8rT1pq0xYz/x?token=s")
        telemetry.ENABLED, token = True, telemetry.current.set(telemetry.new_span("GET /", 2, []))
        try:
            span = telemetry._client_span("get", u)
        finally:
            telemetry.current.reset(token)
            telemetry.ENABLED = False
        attrs = {a["key"]: a["value"]["stringValue"] for a in span["attributes"]}
        self.assertEqual(span["name"], "GET api.example.com:8443")
        self.assertEqual(attrs["url.full"], "https://api.example.com:8443/bot{redacted}/x")
        self.assertNotIn("pass", json.dumps(span))


if __name__ == "__main__":
    unittest.main()
