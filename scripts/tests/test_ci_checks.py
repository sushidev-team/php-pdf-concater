import copy
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path

SCRIPTS = Path(__file__).resolve().parents[1]


def module(filename):
    spec = importlib.util.spec_from_file_location(filename, SCRIPTS / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


coverage = module("check-rust-coverage.py")
corpus = module("pdf-corpus.py")


class CoverageGateTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        for name in ("src/lib.rs", "src/main.rs", "php-ext/src/lib.rs", "src/example_tests.rs"):
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("// test source")
        self.report = {"type": "llvm.coverage.json.export", "data": [{"files": [
            {"filename": str(self.root / name), "summary": {"lines": {"count": 2, "covered": 2}},
             "segments": [[1, 1, 1, True, True, False]]}
            for name in ("src/lib.rs", "src/main.rs", "php-ext/src/lib.rs")]}]}

    def test_accepts_complete_runtime_sources(self):
        self.assertEqual(coverage.validate(self.report, self.root), (6, 3))

    def test_rejects_missing_empty_incomplete_duplicate_and_rounded_reports(self):
        cases = [{}, {"type": "wrong", "data": []}]
        for mutation in ("missing", "partial", "empty", "segments", "duplicate", "rounded"):
            report = copy.deepcopy(self.report)
            files = report["data"][0]["files"]
            if mutation == "missing":
                files.pop()
            elif mutation == "partial":
                files[0]["summary"]["lines"]["covered"] = 1
            elif mutation == "empty":
                files[0]["summary"]["lines"] = {"count": 0, "covered": 0}
            elif mutation == "segments":
                files[0]["segments"] = []
            elif mutation == "duplicate":
                files.append(files[0])
            else:
                files[0]["summary"]["lines"] = {"count": 100000, "covered": 99999}
            cases.append(report)
        for report in cases:
            with self.subTest(report=report):
                with self.assertRaises(ValueError):
                    coverage.validate(report, self.root)

    def test_new_source_file_cannot_escape_the_gate(self):
        (self.root / "src/new_feature.rs").write_text("// unreported feature")
        with self.assertRaises(ValueError):
            coverage.validate(self.report, self.root)


class CorpusIntegrityTest(unittest.TestCase):
    def test_rejects_changed_cached_bytes_without_network(self):
        manifest = json.loads(corpus.MANIFEST.read_text())
        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory)
            first = manifest["files"][0]
            (cache / (first["id"] + ".pdf")).write_bytes(b"changed cached download")
            with self.assertRaisesRegex(RuntimeError, "checksum/size mismatch"):
                corpus.fetch(cache)

    def test_manifest_has_pinned_sources_and_both_failure_cases(self):
        files = json.loads(corpus.MANIFEST.read_text())["files"]
        self.assertEqual(len(files), len({file["id"] for file in files}))
        self.assertGreaterEqual(len(files), 15)
        for file in files:
            self.assertRegex(file["revision"], r"^[0-9a-f]{40}$")
            self.assertRegex(file["sha256"], r"^[0-9a-f]{64}$")
            self.assertIn(file["revision"], file["url"])
            self.assertEqual(file["license"], "CC-BY-SA-4.0")
            self.assertGreater(file["bytes"], 0)
        errors = " ".join(file.get("error", "") for file in files)
        self.assertIn("encrypted input", errors)
        self.assertIn("interactive forms", errors)


if __name__ == "__main__":
    unittest.main()
