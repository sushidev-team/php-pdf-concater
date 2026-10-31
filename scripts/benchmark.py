#!/usr/bin/env python3
"""Each measured merge is a fresh process; external time measures peak RSS and wall time."""
import argparse
import json
import platform
import re
import statistics
import subprocess
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dir", type=Path, default=ROOT / "data")
    parser.add_argument("--runs", type=int, default=5)
    parser.add_argument("--threads", type=int, nargs="+", default=[1, 8])
    parser.add_argument("--batch-size", type=int, default=32)
    parser.add_argument("--output-dir", type=Path, default=ROOT / "output")
    parser.add_argument("--backend", choices=["rust", "php"], default="rust")
    parser.add_argument("--engine", choices=["streaming", "streaming-compressed", "memory"], default="streaming")
    parser.add_argument("--php", default="php", help="PHP executable for native extension measurements")
    extension_suffix = "dylib" if platform.system() == "Darwin" else "so"
    parser.add_argument("--extension", type=Path, default=ROOT / f"target/release/libpdf_concater.{extension_suffix}")
    args = parser.parse_args()
    if args.runs < 1 or args.batch_size < 1 or any(t < 1 for t in args.threads):
        parser.error("runs, batch-size and threads must be positive")
    args.output_dir.mkdir(parents=True, exist_ok=True)
    binary = ROOT / "target/release/pdf-concater"
    results = []
    for threads in args.threads:
        for run in range(1, args.runs + 1):
            name = f"merged-php-t{threads}.pdf" if args.backend == "php" else f"merged-t{threads}.pdf"
            output_pdf = args.output_dir / name
            command = [str(binary), "merge", "--dir", str(args.dir), "--output",
                       str(output_pdf),
                       "--threads", str(threads), "--batch-size", str(args.batch_size),
                       "--engine", args.engine]
            if args.backend == "php":
                command = [args.php, "-n", "-d", f"extension={args.extension.resolve()}",
                           str(ROOT / "php/examples/merge.php"), str(args.dir), str(output_pdf),
                           str(threads), str(args.batch_size), args.engine]
            if platform.system() == "Darwin":
                command = ["/usr/bin/time", "-l", *command]
            elif platform.system() == "Linux":
                command = ["/usr/bin/time", "-f", "peak_rss_kib=%M", *command]
            else:
                raise SystemExit("Peak RSS measurement supports macOS and Linux.")
            started = time.perf_counter()
            proc = subprocess.run(command, capture_output=True, text=True)
            wall_ms = (time.perf_counter() - started) * 1000
            if proc.returncode:
                raise SystemExit(proc.stdout + proc.stderr)
            if platform.system() == "Darwin":
                match = re.search(r"(\d+)\s+maximum resident set size", proc.stderr)
                factor = 1
            else:
                match = re.search(r"peak_rss_kib=(\d+)", proc.stderr)
                factor = 1024
            if not match:
                raise SystemExit(f"No peak RSS available; cannot report a valid measurement:\n{proc.stderr}")
            row = json.loads(proc.stdout)
            row.update(run=run, process_wall_ms=wall_ms,
                       peak_rss_mib=int(match.group(1)) * factor / 1024**2)
            results.append(row)
            print(f"threads={threads} run={run}: process={wall_ms:.2f} ms, "
                  f"merge={row['total_ms']:.2f} ms, peak RSS={row['peak_rss_mib']:.2f} MiB", flush=True)
    summaries = []
    for threads in args.threads:
        rows = [r for r in results if r["threads"] == threads]
        summaries.append({
            "threads": threads,
            "process_wall_median_ms": statistics.median(r["process_wall_ms"] for r in rows),
            "process_wall_min_ms": min(r["process_wall_ms"] for r in rows),
            "process_wall_max_ms": max(r["process_wall_ms"] for r in rows),
            "merge_median_ms": statistics.median(r["total_ms"] for r in rows),
            "peak_rss_max_mib": max(r["peak_rss_mib"] for r in rows),
        })
    report = {
        "backend": args.backend,
        "engine": args.engine,
        "extension": str(args.extension.resolve()) if args.backend == "php" else None,
        "platform": platform.platform(),
        "rustc": subprocess.check_output(["rustc", "--version"], text=True).strip(),
        "input_dir": str(args.dir.resolve()),
        "cache_note": "Filesystem cache is not purged. Generation and earlier runs warm the cache.",
        "timing_note": "process_wall_ms includes process startup, merge, sync_all and teardown; excludes generation, build and verification. macOS sync_all is not F_FULLFSYNC.",
        "runs": results, "summary": summaries,
    }
    report_path = args.output_dir / ("benchmark-php.json" if args.backend == "php" else "benchmark.json")
    report_path.write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(summaries, indent=2))
    print(f"Saved {report_path}")


if __name__ == "__main__":
    main()
