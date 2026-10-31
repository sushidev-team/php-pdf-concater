# Optional native PHP extension

The Composer API works with the Rust executable. For direct calls inside the PHP
process, build the extension with matching PHP development headers and `php-config`:

```sh
cargo build --release --locked -p php-pdf-concater
```

Load the resulting file for a single invocation:

```sh
# macOS
php -d extension="$PWD/target/release/libpdf_concater.dylib" php/tests/library.php
# Linux
php -d extension="$PWD/target/release/libpdf_concater.so" php/tests/library.php
```

For permanent use, copy the file to a stable location, add an `extension=` entry
to the relevant PHP configuration and restart the relevant FPM instance if used.
The build must match the PHP API version, NTS/ZTS configuration, OS and architecture.
Locally tested: PHP 8.5.8 NTS on macOS ARM64. Other extension builds need their own tests.

The extension uses [ext-php-rs](https://ext-php.rs/getting-started/installation.html)
and requires Clang/libclang in addition to Rust and the PHP development environment.
For multiple PHP installations, set `PHP` and `PHP_CONFIG` when building.

When the extension is loaded, `(new SushiDev\PdfConcater\Merger())->merge(...)` selects
it automatically. Configuring a binary explicitly selects the executable backend.
The low-level functions are also available:

```php
$result = pdf_concater_merge_dir('/path/to/input', '/path/to/output.pdf',
    threads: 8, batch_size: 32, engine: 'streaming-compressed');

$result = pdf_concater_merge_files(['/path/first.pdf', '/path/second.pdf'],
    '/path/output.pdf', engine: 'streaming-compressed');
```

Low-level function defaults are 8 workers, batch size 32 and `engine: 'streaming'`.
The Composer wrapper enables compression by default. Function errors become PHP
exceptions. The extension checks `open_basedir` before filesystem operations.
Only owned Rust data enters the worker threads; Zend values remain on the caller thread.

`php/pdf_concater.stub.php` contains IDE declarations. Do not include that file at runtime.

```sh
php -n -d extension="$PWD/target/release/libpdf_concater.dylib" php/tests/integration.php
```
