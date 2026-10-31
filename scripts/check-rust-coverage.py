#!/usr/bin/env python3
"""Fail closed if any runtime Rust source is missing or has uncovered lines."""
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def validate(report, root=ROOT):
    expected = {path.resolve() for directory in (root / "src", root / "php-ext/src")
                for path in directory.rglob("*.rs")
                if not path.name.endswith(("_tests.rs", "-tests.rs")) and path.name != "tests.rs"}
    if report.get("type") != "llvm.coverage.json.export" or not report.get("data"):
        raise ValueError("Missing or invalid LLVM coverage data")
    seen = set()
    total = 0
    for data in report["data"]:
        for file in data["files"]:
            path = Path(file["filename"]).resolve()
            if path not in expected:
                continue
            if path in seen:
                raise ValueError(f"Duplicate runtime source report: {path}")
            seen.add(path)
            lines = file["summary"]["lines"]
            count, covered = lines["count"], lines["covered"]
            if not isinstance(count, int) or not isinstance(covered, int) or count < 1 or covered != count:
                raise ValueError(f"Coverage below 100%: {path.relative_to(root)} ({covered}/{count} lines)")
            if not file.get("segments"):
                raise ValueError(f"Missing executable segments: {path}")
            total += count
    if seen != expected or total < 1:
        raise ValueError(f"Missing runtime sources: {sorted(str(path.relative_to(root)) for path in expected - seen)}")
    return total, len(expected)


def main():
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 scripts/check-rust-coverage.py LLVM.json")
    try:
        total, files = validate(json.loads(Path(sys.argv[1]).read_text()))
    except (OSError, ValueError, KeyError, TypeError) as error:
        raise SystemExit(str(error)) from error
    print(f"100% Rust line coverage: {total}/{total} executable lines across {files} runtime source files")


if __name__ == "__main__":
    main()
