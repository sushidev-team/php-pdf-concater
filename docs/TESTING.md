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

This is **line coverage**, not a claim of 100% branch/path coverage. PCOV collects
PHP executable-line data; it does not measure Rust. Rust integration tests,
formatting and Clippy run separately. CLI/bootstrap scripts, configuration,
Blade templates and dependencies are outside the PHP-class coverage metric;
their behavior is exercised by command and HTTP integration tests.

## GitHub Actions

`.github/workflows/tests.yml` runs on push, pull request and manual dispatch:

- Rust formatting, Clippy and release integration tests on Ubuntu.
- PHP library tests and the strict coverage gate on PHP 8.2/8.5 on Ubuntu and
  PHP 8.5 on macOS, including the real native extension and Laravel 12 package tests.
- Laravel integration tests and the strict coverage gate on PHP 8.5 on Ubuntu.
- HTML and Clover reports uploaded as workflow artifacts.

The workflow needs no publishing credentials and does not commit or deploy.
GitHub-hosted execution starts after the code is pushed; local checks do not imply
that a remote Actions run has already happened.
