# Prebuilt Rust executable

GitHub Actions builds and tests these executables on every push and pull request:

| Target | Platform |
| --- | --- |
| `x86_64-unknown-linux-musl` | Linux x86-64, static musl build |
| `aarch64-unknown-linux-musl` | Linux ARM64, static musl build |
| `x86_64-apple-darwin` | macOS 13+, Intel |
| `aarch64-apple-darwin` | macOS 13+, Apple Silicon |

The Actions run offers archives as artifacts. When Release Please publishes a
GitHub Release, a separate workflow builds the tagged source, checks that both
Rust packages and the executable match the tag, and attaches all four archives
plus `SHA256SUMS`. A manual workflow run can retry an existing release tag.
The release automation's dedicated token permits that release event to trigger
the binary workflow. No extra upload secret is required.

Each archive contains `pdf-concater`, `LICENSE`, this guide and `build.json`.
CI extracts each archive and runs generation, merging and verification with all
three engines on its target architecture before accepting the artifact.

## Install in PHP or Laravel without Rust

Install the Composer package, then download the matching archive and `SHA256SUMS`
from [GitHub Releases](https://github.com/sushidev-team/php-pdf-concater/releases).
For example, on Linux x86-64 (replace `v0.1.0` with your installed release):

```sh
sha256sum --ignore-missing --check SHA256SUMS
tar -xzf pdf-concater-v0.1.0-x86_64-unknown-linux-musl.tar.gz
install -m 0755 pdf-concater /your/application/bin/pdf-concater
```

On macOS use `shasum -a 256` and compare the archive's hash with its exact entry
in `SHA256SUMS` before extracting. The binaries are not Apple-notarized.

In Laravel `.env`:

```dotenv
PDF_CONCATER_BINARY=/your/application/bin/pdf-concater
```

Refresh cached Laravel configuration after changing this value. In plain PHP:

```php
$merger = new \SushiDev\PdfConcater\Merger(
    binary: '/your/application/bin/pdf-concater',
);
$result = $merger->merge($files, '/your/application/storage/merged.pdf');
```

Keep the executable outside public upload directories. Rust and PHP development
headers are unnecessary on this executable-backend installation.

These archives contain the CLI engine. The optional native PHP extension still
needs a build matching PHP's version, thread-safety setting, OS and architecture.
