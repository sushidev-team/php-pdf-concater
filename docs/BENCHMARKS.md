# Benchmarks

The fixtures are reproducible and are excluded from version control.

## Make commands

From the repository root:

```sh
make benchmark                 # 10,000 small PDFs, Rust backend
make benchmark-250kb           # 10,000 PDFs of exactly 250,000 bytes each
make benchmark-php             # Native PHP backend with the small dataset
make benchmark-250kb BACKEND=php
```

These commands build the engine, generate missing fixtures and measure the merge.
Complete existing datasets are reused without overwriting them. A partial or
incompatible dataset is rejected; choose a different data directory in that case.
Generation and compilation are outside the timed merge process.

Useful overrides:

```sh
make benchmark-250kb RUNS=3 THREADS="1 8"
make benchmark-250kb ENGINE=streaming
make benchmark COUNT=100 DATA_DIR=tmp/my-benchmark OUTPUT_DIR=output/my-benchmark
```

Defaults: 5 runs, 8 threads, batch size 32 and lossless stream compression.
Results go to `output/benchmark/` or `output/benchmark-250kb/`, including all
individual timings and peak RSS values in `benchmark.json` / `benchmark-php.json`.
`make help` lists the available commands. The PHP backend measures the native
extension, not the Composer process wrapper.

## Real-document benchmarks

```sh
make benchmark-corpus COUNT=100 RUNS=3
make benchmark-corpus COUNT=10000 RUNS=3
```

The [external corpus](../tests/corpus/README.md) has 13 supported PDFs, including
a 117-page mathematics document, plus two explicit rejection fixtures. Benchmark
inputs cycle through the supported source paths; 10,000 inputs are not 10,000
distinct documents or exactly 250 KB each. Their compressed images and fonts are
more representative than the synthetic whitespace padding.

Reports in `output/corpus/benchmark.json` include actual file/page/byte counts,
individual wall times, engine timings, peak RSS, platform and manifest checksum.
Generation/download/validation are outside the timed process; startup, writes,
sync and teardown are included. The filesystem cache is not purged. CI runs the
100-input case on pushes/PRs and the 10,000-input case weekly or manually.

Correctness tests compare all 143 reordered/repeated pages through three engines
and both PHP backends, including rendered pixels and extracted text. CI adds qpdf
syntax checks. These checks precede benchmarking and fail independently of timing.

A local single-run measurement on the machine below used 10,000 repeated real
inputs: 109,204 pages, 2.25 GB input, 2.18 GB output, 2 workers and batch size 2.
It took **22.48 s process wall time** with **152.92 MiB peak RSS**. The 100-input
case's median across 3 runs was **247.6 ms**, with **28.6 MiB** maximum peak RSS.
These real-document measurements show why the synthetic sub-second result is
not a promise for complex PDFs with many pages and compressed resources.

## Generate synthetic fixtures

```sh
cargo build --release --locked -p pdf-concater
./target/release/pdf-concater generate --dir data --count 10000
./target/release/pdf-concater generate --dir data-250kb --count 10000 --file-bytes 250000
```

The generator does not overwrite existing files. The large case creates exactly
10,000 one-page PDFs of 250,000 bytes each: 2.5 GB in total. `--file-bytes 256000`
would create 250 KiB instead. Padding consists of valid uncompressed whitespace
inside the last page's content stream. The encoded length and cross-reference
positions match the resulting file.

These fixtures measure byte volume and PDF processing. They are not representative
of the object complexity or compression of real scans and embedded fonts.

## Repeat a measurement

```sh
python3 scripts/benchmark.py --backend rust --dir data-250kb \
  --output-dir output/250kb-rust --threads 8 --runs 5 --engine streaming-compressed
```

For the optional native PHP extension:

```sh
cargo build --release --locked -p php-pdf-concater
python3 scripts/benchmark.py --backend php --dir data-250kb \
  --output-dir output/250kb-compressed --threads 8 --runs 5 --engine streaming-compressed
```

The Python runner requires `/usr/bin/time` on macOS or GNU time on Linux. It uses
only Python's standard library. Each run starts a new process and records peak
RSS externally. `--backend php` measures the native extension, not the Composer
process wrapper. `--engine streaming` writes the content without additional
compression; `--engine memory` keeps the full merged object model in memory.

## Recorded results

Measured on 2026-10-31: Apple M1 Max, 10 CPU cores, 64 GB RAM, macOS 26.0.1,
PHP 8.5.8 NTS, Rust 1.97.1, release build. Native PHP, 8 workers, batch size 32:

| Engine | Median process time | Min–max | Highest peak RSS | Output |
| --- | ---: | ---: | ---: | ---: |
| In memory, 3 runs | 1.554 s | 1.408–1.777 s | 2,653.75 MiB | about 2.5 GB |
| Streaming pipeline, 5 runs | 1.226 s | 0.918–2.829 s | 82.81 MiB | about 2.5 GB |
| Pipeline with compression, 5 runs | 0.389 s | 0.382–0.613 s | 63.38 MiB | 19.14 MB |

All five compressed runs finished below one second. The benefit is large because
the generated whitespace compresses well. Existing image or compressed streams
are not recompressed, so real PDFs need their own measurements.

A subsequent single Laravel Artisan run using the Composer process backend
merged the same 10,000 × 250-KB files in about 480 ms for the merge call. That is a
functional integration check, not a repeated Laravel performance benchmark.

The report JSON files are under `output/250kb/` and `output/250kb-compressed/` in
this workspace. They are generated artifacts and will not be included in the repository.

## Measurement boundaries

`process_wall_ms` includes startup, merge, flush, `sync_all()` and process teardown.
Generation, compilation and validation are excluded. The filesystem cache is not
purged. Background I/O and previous runs cause visible variation.
On macOS, `sync_all()` is not `F_FULLFSYNC`; these are not power-loss durability tests.

RSS includes native process memory and is different from the Zend heap reported
by PHP's `memory_get_peak_usage()`. PHP's normal memory limit also does not account
for native Rust allocations or a child process in the same way as its own heap.
See [PHP's memory documentation](https://www.php.net/manual/en/function.memory-get-usage.php).
The Composer API's `elapsed_ms` measures the PHP merge operation, including the
child process where used, but excludes the PHP application's startup.

## Verify the output

```sh
python3 scripts/verify.py --output output/250kb-compressed/merged-php-t8.pdf
./target/release/pdf-concater verify-content --dir data-250kb \
  --input output/250kb-compressed/merged-php-t8.pdf
```

The Python check requires Poppler's `pdfinfo` and `pdftotext`. It verifies every
fixture size, page count and page marker in order. The Rust check compares every
complete decompressed page stream byte for byte and rejects repaired cross-reference
data. All 10,000 streams in the compressed benchmark output passed that comparison.
