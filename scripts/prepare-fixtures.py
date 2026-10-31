#!/usr/bin/env python3
"""Prepare deterministic benchmark inputs without overwriting an existing dataset."""
import argparse
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dir", type=Path, required=True)
    parser.add_argument("--count", type=int, default=10000)
    parser.add_argument("--file-bytes", type=int, default=0)
    args = parser.parse_args()
    if args.count < 1 or args.file_bytes < 0:
        parser.error("count must be positive; file-bytes must be non-negative")
    paths = sorted(p for p in args.dir.iterdir() if p.suffix.lower() == ".pdf") if args.dir.exists() else []
    if paths:
        expected = [f"{i:06}.pdf" for i in range(1, args.count + 1)]
        if [p.name for p in paths] != expected or not all(p.is_file() and not p.is_symlink() for p in paths):
            raise SystemExit("Existing PDF dataset is incomplete or different. Choose another DATA_DIR; files have not been changed.")
        if args.file_bytes and any(p.stat().st_size != args.file_bytes for p in paths):
            raise SystemExit("Existing PDFs do not match --file-bytes. Choose another DATA_DIR; files have not been changed.")
        print(f"Reusing {len(paths)} existing PDFs in {args.dir}.")
        return
    command = [str(ROOT / "target/release/pdf-concater"), "generate", "--dir", str(args.dir), "--count", str(args.count)]
    if args.file_bytes:
        command += ["--file-bytes", str(args.file_bytes)]
    subprocess.run(command, check=True)


if __name__ == "__main__":
    main()
