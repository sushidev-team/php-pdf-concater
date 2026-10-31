# Installation and configuration

Package: `sushidev/php-pdf-concater`  
Repository: https://github.com/sushidev-team/php-pdf-concater  
Maintainer: [Sushi Dev GmbH](https://sushi.dev) · [MIT license](../LICENSE)

## Local Composer installation

Before the first GitHub push, add a local path repository to your PHP application's
`composer.json`. Merge these entries into your existing configuration:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/absolute/path/to/php-pdf-concater",
            "options": {
                "versions": {"sushidev/php-pdf-concater": "dev-master"}
            }
        }
    ],
    "require": {
        "sushidev/php-pdf-concater": "dev-master"
    }
}
```

Then run in your application:

```sh
composer update sushidev/php-pdf-concater
vendor/bin/pdf-concater-build
```

No download or compilation is triggered automatically during Composer installation.
The explicit build requires Cargo. Composer's [path repository documentation](https://getcomposer.org/doc/05-repositories.md#path)
explains local package development.

## GitHub installation

After the repository's `master` branch is published:

```sh
composer config repositories.pdf-concater vcs https://github.com/sushidev-team/php-pdf-concater
composer require sushidev/php-pdf-concater:dev-master
vendor/bin/pdf-concater-build
```

A [VCS repository](https://getcomposer.org/doc/05-repositories.md#vcs) does not require
Packagist registration. A plain `composer require` without the repository entry
requires a later Packagist publication; that has not been performed here.

## Configure the merger

```php
use SushiDev\PdfConcater\Merger;

$merger = new Merger(
    binary: '/opt/pdf-concater', // Optional; the package has a default executable path.
    threads: 8,
    batchSize: 32,
    compress: true,
);
```

The defaults are 8 workers, batch size 32 and lossless compression of previously
uncompressed streams. Already compressed streams are preserved. Explicit lists
allow duplicate files and preserve their order. Directory merges read only direct
PDF files, skip file symlinks and sort by filename.

`$result['elapsed_ms']` measures the complete PHP merge operation.
`$result['total_ms']` is the internal Rust merge time.
`$result['backend']` is `process` or `extension`.

If the native extension is loaded, the wrapper selects it automatically. An
explicit binary path, or the `PDF_CONCATER_BINARY` environment variable, selects
the executable backend. No PHP extension is needed for normal use.

## Laravel service and facade

Laravel discovers `PdfConcaterServiceProvider` from the package's Composer
metadata. No manual provider registration or application service binding is needed.
The facade resolves `SushiDev\PdfConcater\Laravel\PdfConcater` from the container.

```php
use SushiDev\PdfConcater\Facades\PdfConcater;

$result = PdfConcater::merge($files, storage_path('app/merged.pdf'));
$result = PdfConcater::mergeDirectory($directory, storage_path('app/merged.pdf'));
```

For dependency injection into your own service:

```php
use SushiDev\PdfConcater\Laravel\PdfConcater;

final class CreateCombinedPdf
{
    public function __construct(private PdfConcater $pdfs) {}

    public function handle(array $files, string $output): array
    {
        return $this->pdfs->merge($files, $output);
    }
}
```

Resolve your service through Laravel's container, for example by injecting it into
a controller. Facade results, exceptions and filename ordering are the same as
the plain PHP API.

Use `mergeFromDisk` for Laravel Storage sources and a destination disk. See
[Storage disks and streaming](STORAGE.md) for S3 setup and performance details.

To publish `config/pdf-concater.php`:

```sh
php artisan vendor:publish --tag=pdf-concater-config
```

Defaults work without publishing. You can override them through `.env`:

```dotenv
PDF_CONCATER_BINARY=/opt/pdf-concater
PDF_CONCATER_THREADS=8
PDF_CONCATER_BATCH_SIZE=32
PDF_CONCATER_COMPRESS=true
```

Leave `PDF_CONCATER_BINARY` unset to use the package executable or the native
extension. Laravel's config caching works normally; rebuild the config cache and
restart long-running workers after changing settings. The package provider and
facade are integration-tested with Laravel 12 and the example app with Laravel 13.
Testbench and framework testing dependencies are development-only; plain PHP
consumers do not install them.

## Deployment

Build `target/release/pdf-concater` for the target server's OS and architecture,
copy it alongside the PHP application, and configure its location:

```php
$merger = new Merger(binary: '/opt/pdf-concater');
```

The runtime server needs the PHP package and the executable, with permission to
read the input PDFs and write the output directory. It does not need Cargo.
A successful merge replaces the output atomically; an error preserves the previous
output. The output parent directory is created when necessary.

In Laravel, the package service binding reads `PDF_CONCATER_BINARY` from `.env`
for a custom location. See `examples/laravel-app/config/pdf-concater.php`.
The supplied example lock file is tested with PHP 8.5.

## Small standalone example

```sh
cd examples/simple-app
composer install
vendor/bin/pdf-concater-build
php merge.php /path/to/pdfs /path/to/merged.pdf
```

## Direct Rust usage

```sh
cargo build --release --locked -p pdf-concater
./target/release/pdf-concater merge \
  --dir /path/to/pdfs --output /path/to/merged.pdf \
  --threads 8 --engine streaming-compressed
```

`merge-files` accepts an ordered JSON list of paths on standard input. Run
`./target/release/pdf-concater --help` for generation and verification commands.
