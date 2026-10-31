# Laravel PDF Concater example

A minimal Laravel application using `sushidev/php-pdf-concater` through Composer.
The local path repository points to the package two directories above this app.
No GitHub publication is needed to run it here. The package service provider is
automatically discovered by Laravel.

## Run

Requirements: PHP 8.3+, Composer and a current Rust toolchain for the initial build.

```sh
composer install
cp .env.example .env
php artisan key:generate
composer build
composer run dev
```

Keep an existing `.env` instead of overwriting it. The server listens on
http://127.0.0.1:8000. Choose 2–20 PDFs and download the combined result.
The form accepts up to 10 MB per file, subject to your PHP upload limits.
No database migration, Node installation or frontend build is required.

The app uses file-backed sessions and cache. The optional Rust extension is not
required: the default Composer backend starts the Rust executable directly.

## Merge a directory

```sh
php artisan pdf:merge /absolute/path/to/pdfs /absolute/path/to/merged.pdf --json
```

For the dataset already generated in this workspace:

```sh
php artisan pdf:merge ../../data-250kb ../../output/laravel-merged.pdf --json
```

Use the directory command for large batches. The browser demo intentionally limits
an individual upload request to 20 PDFs.

## Tests

```sh
php artisan test --compact
```

The feature tests run the real Rust executable through the Composer package,
verify complete merged page streams against the source fixtures, and exercise
HTTP uploads, downloads, validation and Artisan error handling.
Build first with `composer build` after a fresh checkout.

## Integration points

- The package automatically discovers `PdfConcaterServiceProvider` and binds
  `SushiDev\PdfConcater\Merger` and `SushiDev\PdfConcater\Laravel\PdfConcater`;
  the app needs no custom service binding.
- `SushiDev\PdfConcater\Facades\PdfConcater`: the facade for static-style calls.
- `app/Console/Commands/MergePdfs.php`: the `pdf:merge` command.
- `app/Http/Controllers/MergePdfsController.php`: upload-to-download action.
- `app/Http/Requests/MergePdfsRequest.php`: upload validation.
- `resources/views/merge.blade.php`: the small browser app.
- `config/pdf-concater.php`: executable, thread count, batch size and compression.

A custom executable can be configured through `PDF_CONCATER_BINARY` in `.env`.
The facade's `mergeFromDisk` method combines disk-relative paths and writes to a
chosen Storage disk. See [Storage disks and streaming](../../docs/STORAGE.md).
Generated downloads are removed after sending. The demo is for local use and
contains no user accounts.

See the [package README](../../README.md) for installation in another PHP or
Laravel application and the planned GitHub repository.
