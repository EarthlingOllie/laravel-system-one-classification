"""The container probe: healthy only once the configured checkpoints are resident."""
import json
import socket
import subprocess
import sys
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer

from _support import DOCKER, clean_env

PROBE = DOCKER / "laya" / "healthcheck.py"


class FakeLaya:
    """A stand-in `GET /health` that answers whatever the test sets."""

    def __init__(self):
        self.payload = {"status": "ok", "loaded": []}
        self.status = 200
        self.seen_auth = []
        owner = self

        class Handler(BaseHTTPRequestHandler):
            def do_GET(self):
                owner.seen_auth.append(self.headers.get("Authorization"))
                body = json.dumps(owner.payload).encode()
                self.send_response(owner.status if self.path == "/health" else 404)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *args):
                pass

        self.server = HTTPServer(("127.0.0.1", 0), Handler)
        self.port = self.server.server_address[1]
        threading.Thread(target=self.server.serve_forever, daemon=True).start()

    def close(self):
        self.server.shutdown()
        self.server.server_close()


def free_port():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


class HealthcheckTest(unittest.TestCase):
    def setUp(self):
        self.laya = FakeLaya()
        self.addCleanup(self.laya.close)

    def probe(self, port=None, **env):
        env.setdefault("LAYA_PORT", str(port or self.laya.port))
        result = subprocess.run([sys.executable, "-I", str(PROBE)], capture_output=True, text=True,
                                timeout=30, env=clean_env(env))
        return result.returncode, result.stdout + result.stderr

    def test_unhealthy_when_nothing_listens(self):
        code, out = self.probe(port=free_port())
        self.assertEqual(code, 1, out)

    def test_unhealthy_while_no_checkpoint_is_loaded(self):
        code, out = self.probe(LAYA_MODELS="english")
        self.assertEqual(code, 1)
        self.assertIn("english", out)

    def test_healthy_once_the_english_checkpoint_is_loaded(self):
        self.laya.payload = {"status": "ok", "loaded": ["english"]}
        code, out = self.probe(LAYA_MODELS="english")
        self.assertEqual(code, 0, out)

    def test_waits_for_every_configured_checkpoint(self):
        self.laya.payload = {"status": "ok", "loaded": ["english"]}
        code, _ = self.probe(LAYA_MODELS="english, multilingual")
        self.assertEqual(code, 1)
        self.laya.payload = {"status": "ok", "loaded": ["multilingual", "english"]}
        code, out = self.probe(LAYA_MODELS="english, multilingual")
        self.assertEqual(code, 0, out)

    def test_reads_the_checkpoint_aliases_laya_accepts(self):
        self.laya.payload = {"status": "ok", "loaded": ["english", "multilingual"]}
        code, out = self.probe(LAYA_MODELS="EN,ml")
        self.assertEqual(code, 0, out)

    def test_an_empty_model_list_means_every_checkpoint(self):
        self.laya.payload = {"status": "ok", "loaded": ["english", "multilingual"]}
        self.assertEqual(self.probe(LAYA_MODELS="")[0], 1)
        self.laya.payload = {"status": "ok", "loaded": ["english", "multilingual", "typed-decisions"]}
        self.assertEqual(self.probe(LAYA_MODELS="")[0], 0)

    def test_without_preload_liveness_is_enough(self):
        # LAYA_PRELOAD=0 loads lazily, so nothing is resident until the first request.
        code, out = self.probe(LAYA_MODELS="english", LAYA_PRELOAD="0")
        self.assertEqual(code, 0, out)

    def test_unhealthy_on_a_server_error(self):
        self.laya.status = 500
        self.laya.payload = {"status": "ok", "loaded": ["english"]}
        self.assertEqual(self.probe(LAYA_MODELS="english")[0], 1)

    def test_sends_the_bearer_key_when_one_is_configured(self):
        self.laya.payload = {"status": "ok", "loaded": ["english"]}
        code, out = self.probe(LAYA_MODELS="english", LAYA_API_KEY="s3cret")
        self.assertEqual(code, 0, out)
        self.assertEqual(self.laya.seen_auth[-1], "Bearer s3cret")

    def test_sends_no_authorization_header_without_a_key(self):
        self.laya.payload = {"status": "ok", "loaded": ["english"]}
        self.probe(LAYA_MODELS="english")
        self.assertIsNone(self.laya.seen_auth[-1])


if __name__ == "__main__":
    unittest.main()
