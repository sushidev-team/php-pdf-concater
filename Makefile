.DEFAULT_GOAL := help

PYTHON ?= python3
COUNT ?= 10000
RUNS ?= 5
THREADS ?= 8
BATCH_SIZE ?= 32
ENGINE ?= streaming-compressed
BACKEND ?= rust
DATA_DIR ?= data
OUTPUT_DIR ?= output/benchmark
FILE_BYTES ?= 0
LARGE_DATA_DIR ?= data-250kb
LARGE_OUTPUT_DIR ?= output/benchmark-250kb

.PHONY: help build extension fixtures benchmark benchmark-250kb benchmark-php benchmark-corpus test test-corpus coverage coverage-rust

help:
	@printf '%s\n' \
	  'make benchmark        Build, prepare 10,000 small PDFs and measure Rust' \
	  'make benchmark-250kb  Same test with exactly 250,000 bytes per PDF' \
	  'make benchmark-php    Measure the native PHP extension' \
	  'make test             Run PHP and Rust tests' \
	  'make benchmark-corpus Measure pinned real PDFs (override COUNT and RUNS)' \
	  'make test-corpus      Compare real PDFs through Rust and Composer' \
	  'make coverage         Run all PHP/Laravel/Rust coverage gates' \
	  'make coverage-rust    Run Rust/native coverage only (cargo-llvm-cov)' \
	  'Options: RUNS=5 THREADS="1 8" ENGINE=streaming BACKEND=rust COUNT=10000'

build:
	cargo build --release --locked -p pdf-concater

extension:
	cargo build --release --locked -p php-pdf-concater

fixtures: build
	$(PYTHON) scripts/prepare-fixtures.py --dir "$(DATA_DIR)" --count "$(COUNT)" --file-bytes "$(FILE_BYTES)"

benchmark: fixtures
	@if [ "$(BACKEND)" = "php" ]; then $(MAKE) extension; fi
	$(PYTHON) scripts/benchmark.py --dir "$(DATA_DIR)" --output-dir "$(OUTPUT_DIR)" \
	  --runs "$(RUNS)" --threads $(THREADS) --batch-size "$(BATCH_SIZE)" --engine "$(ENGINE)" --backend "$(BACKEND)"

benchmark-250kb:
	$(MAKE) benchmark DATA_DIR="$(LARGE_DATA_DIR)" OUTPUT_DIR="$(LARGE_OUTPUT_DIR)" FILE_BYTES=250000

benchmark-php:
	$(MAKE) benchmark BACKEND=php

test:
	composer test
	cargo test --release --locked -p pdf-concater
	$(PYTHON) -m unittest discover -s scripts/tests -v

test-corpus: build
	$(PYTHON) scripts/pdf-corpus.py test --php php

benchmark-corpus: build
	$(PYTHON) scripts/pdf-corpus.py benchmark --count "$(COUNT)" --runs "$(RUNS)" --engines "$(ENGINE)"

coverage:
	composer test:coverage
	bash scripts/run-rust-coverage.sh

coverage-rust:
	bash scripts/run-rust-coverage.sh
