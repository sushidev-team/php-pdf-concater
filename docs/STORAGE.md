# Laravel Storage disks and streaming

Use configured Laravel disks such as `local`, `public`, `s3`, or a separate
archive disk. The service provider is auto-discovered.

```php
use SushiDev\PdfConcater\Facades\PdfConcater;

$result = PdfConcater::mergeFromDisk(
    ['invoices/first.pdf', 'invoices/second.pdf'],
    'exports/combined.pdf',
    disk: 's3',
    outputDisk: 'archive',
    options: ['visibility' => 'private', 'ContentType' => 'application/pdf'],
);
```

Paths are relative to their disk root. Input order and repeated sources are
preserved. Omit `outputDisk` to write to the source disk. Omit both disk names to
use Laravel's default disk. Choose an output path different from every input.
Flysystem's normalizer rejects paths escaping the disk root and control characters.

For dependency injection, use `SushiDev\PdfConcater\Laravel\PdfConcater`. The
facade and this service resolve the same container singleton. Its `merge`,
`mergeDirectory` and `backend` methods also expose the local-path API. The
framework-independent `SushiDev\PdfConcater\Merger` remains injectable too.

## Configure S3 and temporary storage

Install the same S3 adapter Laravel normally uses:

```sh
composer require league/flysystem-aws-s3-v3:^3.0
```

Configure the source and destination disks in `config/filesystems.php`, including
their buckets, credentials and optional S3-compatible endpoint. This library uses
Laravel's filesystem factory and does not create its own AWS client.

Publish package configuration when you need to customize staging:

```sh
php artisan vendor:publish --tag=pdf-concater-config
```

```dotenv
PDF_CONCATER_TEMP_DIRECTORY=/your/application/storage/app/pdf-staging
PDF_CONCATER_THREADS=4
PDF_CONCATER_BATCH_SIZE=16
```

The temporary parent defaults to the system temporary directory. Every merge gets
a random private workspace, with files removed in `finally` on success and failure.
Use sufficient local disk space for the unique downloaded PDFs and merged output.
Refresh cached Laravel configuration after changing environment settings.

## Stream the result to a browser

After storing the result, Laravel can stream it as a download:

```php
use Illuminate\Support\Facades\Storage;

return Storage::disk('archive')->download(
    $result['path'],
    'combined.pdf',
    ['Content-Type' => 'application/pdf'],
);
```

For an application-owned stream, call `Storage::disk('archive')->readStream($result['path'])`
and close the resource when finished. The merge API returns metrics and the stored
path; it does not return the full PDF as a PHP string.

## Performance behavior

- **Local → local:** direct paths into Rust, with atomic output replacement and
  no staging copies. The destination disk's configured visibility is applied.
- **Remote input:** `readStream` plus bounded stream copying to local files. Files
  are downloaded one at a time; repeated paths reuse the same staged file.
- **Remote output:** Rust writes a local output, then `writeStream` uploads it.
  Additional write options also use this path for a local destination, so the
  adapter can apply them. Read-only disk configuration uses the adapter's writer.

Rust parses seekable files. Remote downloads finish before the merge, and the
result is uploaded after the merge finishes. This keeps PHP memory use low while
still adding network and local I/O time. A remote disk's overwrite/failure
semantics are determined by its adapter. A merge failure happens before upload;
a failed upload is not a cross-provider atomic transaction.

The result includes the usual engine metrics plus:

| Field | Meaning |
| --- | --- |
| `elapsed_ms` | Complete storage operation, including transfer time |
| `merge_elapsed_ms` | PHP merger backend execution time |
| `input_io_ms` / `output_io_ms` | Source preparation and output publication time |
| `staged_files` / `staged_input_bytes` | Unique downloaded inputs and their size |
| `storage_mode` | `local` or `streams` |
| `path` | Stored path relative to the destination disk |

Use a queue job for large batches. Tune Rust workers for the CPU budget of each
worker rather than assigning every concurrent job all CPU cores.

## Tests and GitHub Actions

Package tests cover local, streamed, cross-disk and read-only paths, repeated
sources, ordering, failed reads/writes, visibility failures and workspace cleanup.
All package source remains inside the strict 100% PHP line-coverage gate.
The Laravel 13 example verifies the auto-discovered facade with Storage fakes.

`storage.yml` additionally starts an isolated Moto HTTP S3 server and exercises
the real Flysystem AWS adapter through both the process and native PHP backends.
It compares complete output hashes for local/local, S3/S3 and both cross-disk
directions using 100 repeated inputs from the pinned complex corpus. No cloud
account or real AWS secrets are needed. Reports include transfer times and PHP
heap usage, which excludes Rust/native allocations. Local HTTP does not model
internet latency or a cloud provider's throughput.

To run the same integration locally after building both backends:

```sh
python3 -m venv tmp/storage-python
tmp/storage-python/bin/python -m pip install -r tests/storage/requirements.txt
python3 scripts/pdf-corpus.py fetch
tmp/storage-python/bin/python scripts/run-storage-tests.py \
  --extension target/release/libpdf_concater.so --count 100 --corpus
```

Use `.dylib` on macOS. Reports go to ignored `output/storage/`. The external-server
suite is separate from normal PHPUnit runs; its dedicated CI command fails on skips.
