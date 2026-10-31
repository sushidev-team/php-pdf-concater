# SushiDev PDF Concater

Merge PDFs from PHP or Laravel with a fast Rust engine.

## Requirements

PHP 8.2+, Composer 2.2+, and a current Rust toolchain for the initial build.
The executable backend uses PHP's `proc_open`. Build for the OS and architecture
running PHP; Linux and macOS are supported.

## Quick start

From this checkout:

```sh
composer install
composer build
php bin/pdf-concater php/tests/Fixtures output/merged.pdf
```

This creates a two-page PDF from the included test fixtures. Replace
`php/tests/Fixtures` with your own PDF directory. Keep the output outside that directory.

`composer build` compiles the Rust executable once. It does not install a PHP
extension or change PHP configuration. A compatible prebuilt executable can be
copied to production; Rust is only needed when building.

## Use in PHP

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use SushiDev\PdfConcater\Merger;

$result = (new Merger())->merge(
    [__DIR__ . '/first.pdf', __DIR__ . '/second.pdf'],
    __DIR__ . '/output/merged.pdf',
);

echo "Merged {$result['pages']} pages.\n";
```

For an entire directory:

```php
$result = (new Merger())->mergeDirectory('/path/to/pdfs', '/path/to/merged.pdf');
```

File lists keep their supplied order; directories are sorted by filename.
Lossless compression is enabled by default. Errors throw
`SushiDev\PdfConcater\MergeException`. A failed merge preserves an existing output.

## Install in an existing application

Once the code has been published to the repository's `main` branch:

```sh
composer config repositories.pdf-concater vcs https://github.com/sushidev-team/php-pdf-concater
composer require sushidev/php-pdf-concater:dev-main
vendor/bin/pdf-concater-build
```

Composer installs the PHP library; the last command builds the Rust executable.
For installation from your local checkout before publication, see
[local installation](docs/INSTALLATION.md#local-composer-installation).

## Laravel integration

The package registers its service provider automatically. Use the facade:

```php
use SushiDev\PdfConcater\Facades\PdfConcater;

$result = PdfConcater::merge($files, storage_path('app/merged.pdf'));
```

Or inject `SushiDev\PdfConcater\Merger` into your controller, job or service.
Both resolve the same configured service from Laravel's container.

Publish the optional configuration:

```sh
php artisan vendor:publish --tag=pdf-concater-config
```

Configure `PDF_CONCATER_BINARY`, `PDF_CONCATER_THREADS`, `PDF_CONCATER_BATCH_SIZE`
and `PDF_CONCATER_COMPRESS` in `.env`. See the [Laravel configuration guide](docs/INSTALLATION.md#laravel-service-and-facade).

## Laravel example

The included Laravel 13 application has a small PDF upload and download page.
Use PHP 8.5 for the supplied example lock file.

```sh
cd examples/laravel-app
composer run setup
composer run dev
```

On a fresh checkout, `setup` installs dependencies, prepares `.env`, generates
the application key and builds Rust. Run it once; use `composer run dev` for
subsequent starts. Open **http://127.0.0.1:8000** and choose at least two PDFs.
No database migration or frontend build is needed.

To merge a large directory from Laravel:

```sh
php artisan pdf:merge /path/to/pdfs /path/to/merged.pdf --json
```

## Tests and CI

```sh
composer test
```

The GitHub Actions workflow runs PHP, Laravel and Rust tests on pushes and pull
requests. Its coverage gate requires **100% executable-line coverage** of
`php/src/` and `examples/laravel-app/app/`, including failure paths and the optional
native PHP backend. Reports and caches are ignored by Git.

See [testing and coverage](docs/TESTING.md) for the full local coverage command,
requirements, scope and CI checks.

## Benchmarks

```sh
make benchmark           # 10,000 small PDFs
make benchmark-250kb     # 10,000 PDFs, exactly 250 KB each
```

The targets build Rust, prepare or reuse the datasets, and measure time and peak
RAM. See [benchmark options](docs/BENCHMARKS.md#make-commands).

## More information

- [Installation, deployment and configuration](docs/INSTALLATION.md)
- [Laravel example](examples/laravel-app/README.md)
- [Optional native PHP extension](docs/EXTENSION.md)
- [Benchmarks](docs/BENCHMARKS.md)

The merger combines pages and their inherited resources. It does not combine
bookmarks, document metadata, attachments or form definitions. Encrypted PDFs and
interactive forms are rejected. Performance depends on the actual PDF contents;
see the benchmark notes before relying on the synthetic results.

## License

[MIT](LICENSE) · Copyright © 2026 [Sushi Dev GmbH](https://sushi.dev).
