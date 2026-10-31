#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."
export CARGO_TARGET_DIR="$PWD/target/rust-coverage"
mkdir -p coverage/rust

# Instrument both normal Rust tests and the cdylib loaded by a real PHP process.
# Keep these builds separate from uninstrumented release/benchmark executables.
eval "$(cargo llvm-cov show-env --sh)"
cargo llvm-cov clean --workspace
cargo test --locked -p pdf-concater
cargo build --locked -p php-pdf-concater
case "$(uname -s)" in
  Darwin) extension="$CARGO_TARGET_DIR/debug/libpdf_concater.dylib" ;;
  Linux) extension="$CARGO_TARGET_DIR/debug/libpdf_concater.so" ;;
  *) echo 'Rust/native coverage supports Linux and macOS.' >&2; exit 1 ;;
esac
php -n -d "extension=$extension" php/tests/integration.php

cargo llvm-cov report -p pdf-concater -p php-pdf-concater --json --output-path coverage/rust/coverage.json
cargo llvm-cov report -p pdf-concater -p php-pdf-concater --lcov --output-path coverage/rust/lcov.info
cargo llvm-cov report -p pdf-concater -p php-pdf-concater --html --output-dir coverage/rust
python3 scripts/check-rust-coverage.py coverage/rust/coverage.json
cargo llvm-cov report -p pdf-concater -p php-pdf-concater
