#!/usr/bin/env python3
"""Package and smoke-test a native release executable before uploading it."""
import argparse
import hashlib
import io
import json
import subprocess
import tarfile
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def checked(command, **kwargs):
    return subprocess.check_output([str(part) for part in command], **kwargs)


def package(binary, target, destination, tag=""):
    metadata = json.loads(checked(["cargo", "metadata", "--locked", "--offline", "--no-deps", "--format-version", "1"], cwd=ROOT))
    versions = {item["version"] for item in metadata["packages"] if item["id"] in metadata["workspace_members"]}
    if len(versions) != 1:
        raise ValueError("Rust executable and PHP extension versions must agree")
    version = versions.pop()
    if tag and tag != "v" + version:
        raise ValueError(f"Release tag {tag!r} does not match workspace version v{version}")
    if checked([binary, "--version"]).decode().strip() != "pdf-concater " + version:
        raise ValueError("Executable version does not match Cargo metadata")
    destination.mkdir(parents=True, exist_ok=True)
    archive = destination / f"pdf-concater-v{version}-{target}.tar.gz"
    try:
        commit = checked(["git", "rev-parse", "HEAD"], cwd=ROOT, stderr=subprocess.DEVNULL).decode().strip()
        dirty = bool(checked(["git", "status", "--porcelain"], cwd=ROOT))
    except subprocess.CalledProcessError:
        commit = "local"
        dirty = True
    build = {"version": version, "target": target,
             "rustc": checked(["rustc", "--version"]).decode().strip(),
             "git_commit": commit, "git_dirty": dirty}
    with tarfile.open(archive, "w:gz") as tar:
        tar.add(binary, arcname="pdf-concater")
        tar.add(ROOT / "LICENSE", arcname="LICENSE")
        tar.add(ROOT / "docs/BINARIES.md", arcname="README.md")
        data = (json.dumps(build, indent=2) + "\n").encode()
        info = tarfile.TarInfo("build.json")
        info.size, info.mode = len(data), 0o644
        tar.addfile(info, io.BytesIO(data))
    # Exercise the extracted executable, not just the build-tree executable.
    with tempfile.TemporaryDirectory() as temporary:
        work = Path(temporary)
        with tarfile.open(archive) as tar:
            tar.extractall(work, filter="data")
        executable = work / "pdf-concater"
        checked([executable, "generate", "--dir", work / "input", "--count", "3", "--pages", "2", "--file-bytes", "250000"])
        for engine in ("streaming", "streaming-compressed", "memory"):
            output = work / (engine + ".pdf")
            result = json.loads(checked([executable, "merge", "--dir", work / "input", "--output", output,
                                        "--engine", engine, "--threads", "2", "--batch-size", "1"]))
            if result["pages"] != 6 or result["files"] != 3:
                raise RuntimeError("Packaged executable returned incorrect counts")
            checked([executable, "verify", "--input", output, "--count", "3", "--pages", "2"])
            checked([executable, "verify-content", "--dir", work / "input", "--input", output])
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(archive.suffix + ".sha256").write_text(f"{checksum}  {archive.name}\n")
    print(archive)
    return archive


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--binary", type=Path, required=True)
    parser.add_argument("--target", required=True, choices=("x86_64-unknown-linux-musl", "aarch64-unknown-linux-musl", "x86_64-apple-darwin", "aarch64-apple-darwin"))
    parser.add_argument("--output", type=Path, default=ROOT / "output/binaries")
    parser.add_argument("--tag", default="")
    args = parser.parse_args()
    package(args.binary.resolve(), args.target, args.output.resolve(), args.tag)


if __name__ == "__main__":
    main()
