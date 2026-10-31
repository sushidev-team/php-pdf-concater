#!/usr/bin/env python3
"""Verify generated fixture sizes and every output page independently with Poppler."""
import argparse
import re
import subprocess
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dir", type=Path, default=Path("data-250kb"))
    parser.add_argument("--count", type=int, default=10000)
    parser.add_argument("--pages", type=int, default=1)
    parser.add_argument("--file-bytes", type=int, default=250000)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    paths = sorted(args.dir.glob("*.pdf"))
    expected_names = [f"{i:06}.pdf" for i in range(1, args.count + 1)]
    if [p.name for p in paths] != expected_names:
        raise SystemExit("Unexpected fixture names or count")
    for path in paths:
        if path.stat().st_size != args.file_bytes:
            raise SystemExit(f"Incorrect file size: {path}")
    info = subprocess.run(["pdfinfo", str(args.output)], capture_output=True, check=True)
    if info.stderr:
        raise SystemExit(info.stderr.decode())
    match = re.search(rb"^Pages:\s+(\d+)$", info.stdout, re.M)
    if not match or int(match.group(1)) != args.count * args.pages:
        raise SystemExit("Output page count mismatch")
    result = subprocess.run(["pdftotext", str(args.output), "-"], capture_output=True, check=True)
    if result.stderr:
        raise SystemExit(result.stderr.decode())
    actual = re.findall(rb"Fixture (\d+) / Page (\d+)", result.stdout)
    expected = [(f"{i:06}".encode(), f"{p:04}".encode())
                for i in range(1, args.count + 1) for p in range(1, args.pages + 1)]
    if actual != expected:
        raise SystemExit("Page markers or page order mismatch")
    print(f"Verified {len(paths)} files, exactly {args.file_bytes} bytes each, "
          f"{len(actual)} output pages and correct order: {args.output}")


if __name__ == "__main__":
    main()
