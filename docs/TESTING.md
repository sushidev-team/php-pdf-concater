# Tests and coverage

## Regular tests

From the repository root:

```sh
composer install
composer build
composer test
cargo test --release --locked -p pdf-concater
```

The PHPUnit suite tests the public PHP API, the real Rust process, filename ordering,
repeated sources, malformed backend responses, process failures and resource cleanup.
Orchestra Testbench also checks the real Laravel service binding, facade,
configuration overrides and vendor publishing.
The existing integration script also checks an ordered list of 10,000 paths.
Optional native-backend tests are skipped if its extension is not loaded in a
normal test run; the coverage run below loads and tests that backend too.

For Laravel:

```sh
cd examples/laravel-app
composer run setup
php artisan test --compact
```

On an already configured example, keep the existing `.env` and just run the tests.
Tests invoke the real Rust executable, compare full page contents and exercise
uploads, downloads, validation, Artisan output and the application's existing model.

## Enforce 100% line coverage

Coverage requires PCOV or Xdebug. Full library coverage also needs the native PHP
backend, so install the matching PHP development headers, `php-config` and
Clang/libclang, then build it once:

```sh
cargo build --release --locked -p php-pdf-concater
composer test:coverage
```

Install the Laravel example's Composer dependencies and prepare its `.env` before
running both suites. You can also select either suite:

```sh
composer test:coverage:library
composer test:coverage:laravel
```

HTML and Clover XML reports are generated in `coverage/library/` and
`coverage/laravel/`. These directories are ignored by Git and package archives.

The required coverage scope is:

- All executable PHP lines in `php/src/`, including the Laravel service provider
  and facade.
- All executable PHP lines in `examples/laravel-app/app/`.

Unexecuted source files are included. Coverage-ignore annotations are disabled.
No application classes are excluded from those source directories. Empty classes
and inherited methods add no executable lines of their own.

The gate checks each file and its line data, not a rounded percentage. A missing,
malformed, empty, inconsistent or partially covered report fails. Tests for the
gate itself verify both accepted and rejected reports. Any failed PHPUnit test
also stops the coverage command before the gate is evaluated.

This is **line coverage**, not a claim of 100% branch/path coverage. CLI/bootstrap
PHP scripts, configuration, Blade templates and dependencies are outside the
PHP-class coverage metric; their behavior is exercised by command and HTTP tests.

## Rust and native boundary coverage

The Rust gate separately requires 100% executable-line coverage of every runtime
`.rs` file under `src/` and `php-ext/src/`, including the CLI, serializer, shared
panic boundary and actual extension functions. Native functions are instrumented
and exercised inside a real PHP process, not replaced with PHP mocks.

```sh
rustup component add llvm-tools-preview
cargo install cargo-llvm-cov --version 0.9.0 --locked
make coverage-rust
```

The native build needs PHP development headers / `php-config` and Clang/libclang.
On Ubuntu install `libclang-dev`. The command uses a separate target directory,
cleans old workspace coverage, and generates LLVM JSON, LCOV and HTML reports in
`coverage/rust/`. A strict gate discovers runtime source files and rejects missing
files, empty reports and any uncovered line. No runtime Rust file or error handler
has a coverage-ignore annotation. Cargo build scripts, dependencies, generated
bindings and test source files are outside this runtime metric. Region, branch
and generic-instantiation coverage are different metrics and are not claimed as 100%.

`make coverage` runs the PHP, Laravel and Rust gates together. `make test` also
runs tests for the CI gates and corpus checksum validation.

## Complex external PDFs

```sh
make test-corpus
```

Install Poppler (`poppler-utils` on Ubuntu) first. Add `--require-qpdf` to
`python3 scripts/pdf-corpus.py test` when qpdf is installed. To test the native
backend too, pass `--php php --extension target/release/libpdf_concater.so`
(`.dylib` on macOS). The manifest and separate PDF licenses are documented in
[the corpus guide](../tests/corpus/README.md).

The test downloads 15 pinned, checksum-verified PDFs, checks every rendered page
and extracted text through all three Rust engines and the requested PHP backends,
and verifies rejection/output preservation for encrypted documents and forms.
It compares source and merged pages on the same runner without portable golden
screenshots. Download or checksum failures fail the workflow; tests are not skipped.

## GitHub Actions

`.github/workflows/tests.yml` runs on push, pull request and manual dispatch:

- Rust formatting, Clippy and release integration tests on Ubuntu.
- Instrumented Rust and native PHP boundary tests with strict 100% line coverage.
- Four packaged executables built and smoke-tested on native Linux/macOS runners.
- PHP library tests and the strict coverage gate on PHP 8.2/8.5 on Ubuntu and
  PHP 8.5 on macOS, including the real native extension and Laravel 12 package tests.
- Laravel integration tests and the strict coverage gate on PHP 8.5 on Ubuntu.
- HTML and Clover reports uploaded as workflow artifacts.

`storage.yml` checks actual HTTP S3 streams through Laravel's AWS adapter using
an isolated emulator, both PHP backends and cross-disk output hashes. See
[the storage guide](STORAGE.md#tests-and-github-actions) for the local command.

`pdf-corpus.yml` additionally runs pixel/text equality and qpdf validation, then
records external wall time and peak RSS. Pushes/PRs benchmark 100 repeated real
inputs; weekly and default manual runs benchmark 10,000 inputs. Timing is reported
without a sub-second pass/fail threshold because hosted-runner performance varies.
JSON reports and source attribution are uploaded as artifacts.

The test workflows need no publishing credentials and do not commit or deploy.
GitHub-hosted execution starts after the code is pushed; local checks do not imply
that a remote Actions run has already happened.
