#!/usr/bin/env python3
"""Download a pinned PDF corpus, compare every rendered page, and record benchmarks."""
import argparse
import hashlib
import json
import os
import platform
import re
import shutil
import statistics
import subprocess
import time
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "tests/corpus/manifest.json"
ENGINES = ("streaming", "streaming-compressed", "memory")


def checked(command, **kwargs):
    result = subprocess.run([str(item) for item in command], capture_output=True, timeout=300, **kwargs)
    if result.returncode:
        raise RuntimeError(f"Command failed ({result.returncode}): {command}\n{result.stderr.decode(errors='replace')}")
    return result


def fetch(cache):
    manifest = json.loads(MANIFEST.read_text())
    cache.mkdir(parents=True, exist_ok=True)
    for item in manifest["files"]:
        target = cache / (item["id"] + ".pdf")
        if not target.exists():
            request = urllib.request.Request(item["url"], headers={"User-Agent": "SushiDev-PDF-corpus/1"})
            for attempt in range(3):
                try:
                    with urllib.request.urlopen(request, timeout=45) as response:
                        data = response.read(item["bytes"] + 1)
                    break
                except OSError:
                    if attempt == 2:
                        raise
            if len(data) != item["bytes"] or hashlib.sha256(data).hexdigest() != item["sha256"]:
                raise RuntimeError(f"Download checksum/size mismatch: {item['id']}")
            temporary = target.with_suffix(".download")
            temporary.write_bytes(data)
            temporary.replace(target)
        data = target.read_bytes()
        if len(data) != item["bytes"] or hashlib.sha256(data).hexdigest() != item["sha256"]:
            raise RuntimeError(f"Cached corpus checksum/size mismatch: {target}")
    return manifest


def pages(pdf):
    text = checked(["pdfinfo", pdf]).stdout.decode()
    return int(re.search(r"^Pages:\s+(\d+)$", text, re.M)[1])


def rendered_pages(pdf, directory):
    directory.mkdir(parents=True, exist_ok=True)
    checked(["pdftoppm", "-r", "72", "-cropbox", pdf, directory / "page"])
    files = sorted(directory.glob("page-*.ppm"), key=lambda p: int(p.stem.split("-")[-1]))
    if len(files) != pages(pdf):
        raise RuntimeError(f"Renderer returned an unexpected page count: {pdf}")
    hashes = []
    for file in files:
        hashes.append(hashlib.sha256(file.read_bytes()).hexdigest())
        file.unlink()  # Keep CI disk use bounded; mismatches can be re-rendered from the PDFs.
    return hashes


def text_pages(pdf):
    text = checked(["pdftotext", "-layout", "-enc", "UTF-8", "-eol", "unix", pdf, "-"]).stdout
    result = text.split(b"\f")
    if result[-1] == b"":
        result.pop()
    return [hashlib.sha256(page).hexdigest() for page in result]


def command_for(args, engine, output):
    return [args.binary, "merge-files", "--output", output, "--threads", "2",
            "--batch-size", "2", "--engine", engine]


def adapters(args, output):
    yield "cli-streaming", command_for(args, "streaming", output)
    yield "cli-streaming-compressed", command_for(args, "streaming-compressed", output)
    yield "cli-memory", command_for(args, "memory", output)
    if args.php:
        yield "php-process", [args.php, ROOT / "scripts/pdf-corpus.php", args.binary, output]
    if args.extension:
        yield "php-extension", [args.php or "php", "-n", "-d", f"extension={args.extension}",
                                ROOT / "scripts/pdf-corpus.php", "native", output]


def verify(args, manifest):
    accepted = [item for item in manifest["files"] if item["expect"] == "merge"]
    # Reverse the list and repeat one source to detect ordering/resource-ID collisions.
    ordered = list(reversed(accepted)) + accepted[:1]
    expected_images, expected_text = [], []
    source_hashes = {}
    for item in accepted:
        source = args.cache / (item["id"] + ".pdf")
        if pages(source) != item["pages"]:
            raise RuntimeError(f"Source page count changed: {item['id']}")
        source_hashes[item["id"]] = (
            rendered_pages(source, args.output / "renders" / item["id"]), text_pages(source))
    for item in ordered:
        images, text = source_hashes[item["id"]]
        expected_images.extend(images)
        expected_text.extend(text)
    inputs = json.dumps([str(args.cache / (item["id"] + ".pdf")) for item in ordered]).encode()
    results = []
    output = args.output / "merged.pdf"
    for name, command in adapters(args, output):
        result = json.loads(checked(command, input=inputs).stdout)
        if result["pages"] != len(expected_images) or result["files"] != len(ordered):
            raise RuntimeError(f"Incorrect merge counts: {name}")
        if shutil.which("qpdf"):
            checked(["qpdf", "--check", output])
        images = rendered_pages(output, args.output / "renders" / name)
        if images != expected_images:
            mismatch = [index + 1 for index, pair in enumerate(zip(images, expected_images)) if pair[0] != pair[1]]
            raise RuntimeError(f"Rendered pages differ ({name}), pages {mismatch[:20]}")
        if text_pages(output) != expected_text:
            raise RuntimeError(f"Extracted text/order differs: {name}")
        for item in manifest["files"]:
            if item["expect"] != "reject":
                continue
            output.write_bytes(b"existing output must survive")
            rejected = subprocess.run([str(part) for part in command],
                input=json.dumps([str(args.cache / (item["id"] + ".pdf"))]).encode(),
                capture_output=True, timeout=120)
            if rejected.returncode == 0 or item["error"] not in rejected.stderr.decode(errors="replace"):
                raise RuntimeError(f"Expected rejection failed: {name}, {item['id']}: {rejected.stderr!r}")
            if output.read_bytes() != b"existing output must survive":
                raise RuntimeError(f"Rejection replaced output: {name}")
        results.append({"backend": name, "pages_compared": len(expected_images),
                        "pixel_equal": True, "text_equal": True, "metrics": result})
        print(f"PASS {name}: {len(expected_images)} rendered pages and text equal; rejection preserves output", flush=True)
    return {"platform": platform.platform(), "qpdf": bool(shutil.which("qpdf")),
            "render_dpi": 72, "manifest_sha256": hashlib.sha256(MANIFEST.read_bytes()).hexdigest(),
            "results": results}


def benchmark(args, manifest):
    accepted = [args.cache / (item["id"] + ".pdf") for item in manifest["files"] if item["expect"] == "merge"]
    inputs = json.dumps([str(accepted[index % len(accepted)]) for index in range(args.count)]).encode()
    results = []
    output = args.output / "benchmark.pdf"
    for engine in args.engines:
        for run in range(1, args.runs + 1):
            command = command_for(args, engine, output)
            if platform.system() == "Darwin":
                command = ["/usr/bin/time", "-l", *command]
                rss_pattern, factor = r"(\d+)\s+maximum resident set size", 1
            else:
                command = ["/usr/bin/time", "-f", "peak_rss_kib=%M", *command]
                rss_pattern, factor = r"peak_rss_kib=(\d+)", 1024
            started = time.perf_counter()
            result = checked(command, input=inputs)
            wall_ms = (time.perf_counter() - started) * 1000
            match = re.search(rss_pattern, result.stderr.decode())
            if not match:
                raise RuntimeError("External peak RSS unavailable")
            row = json.loads(result.stdout)
            row.update(run=run, process_wall_ms=wall_ms,
                       peak_rss_mib=int(match[1]) * factor / 1024**2)
            results.append(row)
            print(f"{engine} run {run}: {wall_ms:.1f} ms; {row['peak_rss_mib']:.1f} MiB", flush=True)
    summary = [{"engine": engine,
                "wall_median_ms": statistics.median(row["process_wall_ms"] for row in results if row["engine"] == engine),
                "peak_rss_max_mib": max(row["peak_rss_mib"] for row in results if row["engine"] == engine)}
               for engine in args.engines]
    return {"platform": platform.platform(), "count": args.count, "unique_sources": len(accepted),
            "manifest_sha256": hashlib.sha256(MANIFEST.read_bytes()).hexdigest(),
            "note": "Pinned real PDFs repeated in order, not 10,000 distinct documents. Cache is not purged. "
                    "Timing includes process startup, merge, sync and teardown, excludes download and validation.",
            "runs": results, "summary": summary}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("operation", choices=("fetch", "test", "benchmark"))
    parser.add_argument("--binary", type=Path, default=ROOT / "target/release/pdf-concater")
    parser.add_argument("--cache", type=Path, default=ROOT / "tmp/pdf-corpus")
    parser.add_argument("--output", type=Path, default=ROOT / "output/corpus")
    parser.add_argument("--php", help="Also check the public Composer API using this PHP executable")
    parser.add_argument("--extension", type=Path, help="Also check the native backend through the Composer API")
    parser.add_argument("--require-qpdf", action="store_true")
    parser.add_argument("--count", type=int, default=100)
    parser.add_argument("--runs", type=int, default=3)
    parser.add_argument("--engines", choices=ENGINES, nargs="+", default=["streaming-compressed"])
    args = parser.parse_args()
    if args.count < 1 or args.runs < 1:
        parser.error("count and runs must be positive")
    args.binary, args.cache, args.output = args.binary.resolve(), args.cache.resolve(), args.output.resolve()
    if args.extension:
        args.extension = args.extension.resolve()
    if args.require_qpdf and not shutil.which("qpdf"):
        parser.error("qpdf is required")
    args.output.mkdir(parents=True, exist_ok=True)
    manifest = fetch(args.cache)
    shutil.copyfile(MANIFEST, args.output / "manifest.json")
    shutil.copyfile(ROOT / "tests/corpus/README.md", args.output / "CORPUS-LICENSES.md")
    if args.operation == "fetch":
        print(f"Verified {len(manifest['files'])} pinned downloads")
        return
    report = verify(args, manifest) if args.operation == "test" else benchmark(args, manifest)
    (args.output / (args.operation + ".json")).write_text(json.dumps(report, indent=2) + "\n")


if __name__ == "__main__":
    main()
