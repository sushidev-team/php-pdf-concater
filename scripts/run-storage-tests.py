#!/usr/bin/env python3
"""Run Laravel's real S3 adapter against an isolated local HTTP S3 emulator."""
import argparse
import os
import logging
import socket
import subprocess
from pathlib import Path
from moto.server import ThreadedMotoServer

ROOT = Path(__file__).resolve().parents[1]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--extension", type=Path, required=True)
    parser.add_argument("--count", type=int, default=100)
    parser.add_argument("--corpus", action="store_true")
    args = parser.parse_args()
    if args.count < 2:
        parser.error("count must be at least two")
    logging.getLogger('werkzeug').setLevel(logging.ERROR)
    # Fail immediately in environments that forbid listening sockets instead of
    # waiting for Moto's ready event after its background thread exits.
    with socket.socket() as listener:
        listener.bind(('127.0.0.1', 0))
        port = listener.getsockname()[1]
    server = ThreadedMotoServer(ip_address="127.0.0.1", port=port)
    server.start()
    try:
        host, port = server.get_host_and_port()
        environment = dict(os.environ, PDF_CONCATER_S3_ENDPOINT=f"http://{host}:{port}",
                           PDF_CONCATER_STORAGE_COUNT=str(args.count),
                           PDF_CONCATER_STORAGE_CORPUS="1" if args.corpus else "0",
                           AWS_EC2_METADATA_DISABLED="true")
        command = ["php", "-d", f"extension={args.extension.resolve()}", "vendor/bin/phpunit", "php/tests/Storage",
                   "--group", "storage-s3", "--fail-on-skipped"]
        result = subprocess.run(command, cwd=ROOT, env=environment, timeout=600)
    finally:
        server.stop()
    raise SystemExit(result.returncode)


if __name__ == "__main__":
    main()
