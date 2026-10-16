import unittest

from _support import compose_config


def seconds(value):
    """Compose renders durations as Go strings ("20m0s") or nanoseconds."""
    if isinstance(value, (int, float)):
        return value / 1e9
    total, num = 0.0, ""
    for ch in value:
        if ch.isdigit() or ch == ".":
            num += ch
        else:
            total += float(num) * {"h": 3600, "m": 60, "s": 1}[ch]
            num = ""
    return total


def cache_mount(service):
    return [v for v in service["volumes"] if v["target"] == service["environment"]["HF_HOME"]]


class ComposeTest(unittest.TestCase):
    def setUp(self):
        self.config = compose_config("compose.yaml")
        self.laya = self.config["services"]["laya"]

    def test_it_serves_port_8000_on_loopback_only(self):
        ports = self.laya["ports"]
        self.assertEqual(len(ports), 1)
        self.assertEqual(ports[0]["host_ip"], "127.0.0.1")
        self.assertEqual(str(ports[0]["published"]), "8000")
        self.assertEqual(ports[0]["target"], 8000)

    def test_the_host_port_can_be_changed_but_stays_on_loopback(self):
        laya = compose_config("compose.yaml", env={"LAYA_HOST_PORT": "18000"})["services"]["laya"]
        self.assertEqual(str(laya["ports"][0]["published"]), "18000")
        self.assertEqual(laya["ports"][0]["host_ip"], "127.0.0.1")

    def test_the_hugging_face_cache_is_a_named_volume(self):
        cache = cache_mount(self.laya)
        self.assertEqual(len(cache), 1)
        self.assertEqual(cache[0]["type"], "volume")
        self.assertIn(cache[0]["source"], self.config["volumes"])

    def test_the_cache_can_be_a_host_directory_for_ci(self):
        laya = compose_config("compose.yaml", env={"LAYA_HF_CACHE": "/tmp/hf"})["services"]["laya"]
        cache = cache_mount(laya)[0]
        self.assertEqual(cache["type"], "bind")
        self.assertEqual(cache["source"], "/tmp/hf")

    def test_defaults_load_only_the_reviewed_english_checkpoint_at_start(self):
        env = self.laya["environment"]
        self.assertEqual(env["LAYA_REVISION"], "reviewed")
        self.assertEqual(env["LAYA_MODELS"], "english")
        self.assertEqual(env["LAYA_PRELOAD"], "1")
        # Without this, text with no language evidence routes to (and downloads) multilingual.
        self.assertEqual(env["LAYA_DEFAULT_MODEL"], "english")

    def test_multilingual_is_opt_in_through_the_environment(self):
        env = compose_config("compose.yaml", env={"LAYA_MODELS": "english,multilingual"})["services"]["laya"]["environment"]
        self.assertEqual(env["LAYA_MODELS"], "english,multilingual")

    def test_there_is_no_api_key_by_default(self):
        self.assertEqual(self.laya["environment"].get("LAYA_API_KEY") or "", "")

    def test_the_healthcheck_runs_the_python_probe_with_a_long_start_period(self):
        hc = self.laya["healthcheck"]
        self.assertIn("/opt/laya/healthcheck.py", " ".join(hc["test"]))
        # First start downloads ~1.6 GB of weights before the checkpoint can load.
        self.assertGreaterEqual(seconds(hc["start_period"]), 600)

    def test_the_image_is_built_from_our_dockerfile(self):
        self.assertTrue(self.laya["build"]["context"].endswith("/docker/laya"))
        self.assertIn("0.4.8", self.laya["image"])


class CudaOverrideTest(unittest.TestCase):
    def setUp(self):
        self.laya = compose_config("compose.yaml", "compose.cuda.yaml")["services"]["laya"]

    def test_it_reserves_an_nvidia_gpu(self):
        devices = self.laya["deploy"]["resources"]["reservations"]["devices"]
        self.assertEqual(devices[0]["driver"], "nvidia")
        self.assertIn("gpu", devices[0]["capabilities"])

    def test_it_builds_a_cuda_torch_into_a_separate_image(self):
        self.assertIn("/whl/cu", self.laya["build"]["args"]["TORCH_INDEX_URL"])
        self.assertIn("cuda", self.laya["image"])
        self.assertEqual(self.laya["environment"]["LAYA_DEVICE"], "cuda")

    def test_it_keeps_the_base_defaults(self):
        self.assertEqual(self.laya["environment"]["LAYA_REVISION"], "reviewed")
        self.assertEqual(self.laya["ports"][0]["host_ip"], "127.0.0.1")


if __name__ == "__main__":
    unittest.main()
