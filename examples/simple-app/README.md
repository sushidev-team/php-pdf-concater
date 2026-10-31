# Simple PHP example

This app installs the local Composer package from `../..` and merges a directory.

```sh
composer install
vendor/bin/pdf-concater-build
php merge.php /absolute/path/to/pdfs /absolute/path/to/merged.pdf
```

Use a current Rust toolchain for the first build. The built executable can then be
used without Cargo. The PHP library requires PHP 8.2+ and `proc_open`, or the
optional native extension.

See the [package README](../../README.md) for the PHP API and deployment options.
