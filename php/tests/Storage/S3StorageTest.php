<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Tests\Storage;

use Aws\S3\S3Client;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use SushiDev\PdfConcater\Facades\PdfConcater;
use SushiDev\PdfConcater\Merger;
use SushiDev\PdfConcater\PdfConcaterServiceProvider;

#[Group('storage-s3')]
final class S3StorageTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PdfConcaterServiceProvider::class];
    }

    #[DataProvider('backends')]
    public function test_real_s3_streams_match_local_outputs_and_report_transfer_costs(string $backend): void
    {
        $endpoint = getenv('PDF_CONCATER_S3_ENDPOINT');
        if (! $endpoint) {
            self::markTestSkipped('Use scripts/run-storage-tests.py to start the isolated S3 HTTP server.');
        }
        if ($backend === 'extension' && ! extension_loaded('pdf_concater')) {
            self::markTestSkipped('The S3 runner loads the native extension for both backend cases.');
        }
        $root = dirname(__DIR__, 3);
        $temporary = $root.'/tmp/s3-test-'.bin2hex(random_bytes(6));
        $sourceBucket = 'pdf-source-'.bin2hex(random_bytes(8));
        $targetBucket = 'pdf-target-'.bin2hex(random_bytes(8));
        $settings = ['driver' => 's3', 'region' => 'us-east-1', 'key' => 'test', 'secret' => 'test',
            'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'throw' => true];
        config([
            'pdf-concater.binary' => $backend === 'process' ? $root.'/target/release/pdf-concater' : null,
            'pdf-concater.threads' => 2, 'pdf-concater.batch_size' => 2,
            'pdf-concater.temporary_directory' => $temporary.'/staging',
            'filesystems.disks.source-s3' => [...$settings, 'bucket' => $sourceBucket],
            'filesystems.disks.target-s3' => [...$settings, 'bucket' => $targetBucket],
            'filesystems.disks.source-local' => ['driver' => 'local', 'root' => $temporary.'/source', 'throw' => true],
            'filesystems.disks.target-local' => ['driver' => 'local', 'root' => $temporary.'/target', 'throw' => true],
        ]);
        self::assertSame($backend, $this->app->make(Merger::class)->backend());
        $client = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint,
            'use_path_style_endpoint' => true, 'credentials' => ['key' => 'test', 'secret' => 'test']]);
        $client->createBucket(['Bucket' => $sourceBucket]);
        $client->createBucket(['Bucket' => $targetBucket]);
        try {
            $fixtures = [[$root.'/php/tests/Fixtures/first.pdf', 1], [$root.'/php/tests/Fixtures/second.pdf', 1]];
            if (getenv('PDF_CONCATER_STORAGE_CORPUS') === '1') {
                $manifest = json_decode(file_get_contents($root.'/tests/corpus/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
                $fixtures = array_map(static fn (array $file): array => [$root.'/tmp/pdf-corpus/'.$file['id'].'.pdf', $file['pages']],
                    array_values(array_filter($manifest['files'], static fn (array $file): bool => $file['expect'] === 'merge')));
            }
            $keys = [];
            foreach ($fixtures as [$file]) {
                $key = 'input/'.basename($file);
                $keys[] = $key;
                foreach (['source-local', 'source-s3'] as $disk) {
                    $stream = fopen($file, 'rb');
                    try {
                        self::assertTrue(Storage::disk($disk)->writeStream($key, $stream));
                    } finally {
                        fclose($stream);
                    }
                }
            }
            $files = [];
            $expectedPages = 0;
            $count = (int) (getenv('PDF_CONCATER_STORAGE_COUNT') ?: 100);
            for ($index = 0; $index < $count; $index++) {
                $files[] = $keys[$index % count($keys)];
                $expectedPages += $fixtures[$index % count($fixtures)][1];
            }
            $measurements = [];
            $baseline = null;
            foreach ([['source-local', 'target-local'], ['source-s3', 'target-s3'], ['source-local', 'target-s3'], ['source-s3', 'target-local']] as $index => [$source, $target]) {
                memory_reset_peak_usage();
                $result = PdfConcater::mergeFromDisk($files, 'output/'.$index.'.pdf', disk: $source, outputDisk: $target);
                self::assertSame($expectedPages, $result['pages']);
                self::assertSame($count, $result['files']);
                $stream = Storage::disk($target)->readStream($result['path']);
                try {
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    $actual = hash_final($hash);
                } finally {
                    fclose($stream);
                }
                $baseline ??= $actual;
                self::assertSame($baseline, $actual, 'All disk paths must produce the exact same PDF bytes.');
                self::assertSame($source === 'source-local' ? 0 : min($count, count($keys)), $result['staged_files']);
                $measurements[] = ['source' => $source, 'destination' => $target,
                    'php_peak_heap_mib' => memory_get_peak_usage(true) / 1024 ** 2, ...$result];
                self::assertSame([], glob($temporary.'/staging/pdf-concater-*') ?: []);
            }
            $report = ['backend' => $backend, 'emulator' => 'Moto HTTP S3', 'count' => $count,
                'unique_sources' => count($keys), 'pages' => $expectedPages,
                'note' => 'Merge call wall time includes disk transfers. PHP heap excludes Rust/native memory. Local HTTP S3 does not model WAN latency.',
                'results' => $measurements];
            (new Filesystem)->ensureDirectoryExists($root.'/output/storage');
            file_put_contents($root.'/output/storage/benchmark-'.$backend.'.json', json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
        } finally {
            foreach ([$sourceBucket, $targetBucket] as $bucket) {
                $objects = $client->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? [];
                if ($objects !== []) {
                    $client->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => array_map(static fn (array $object): array => ['Key' => $object['Key']], $objects)]]);
                }
                $client->deleteBucket(['Bucket' => $bucket]);
            }
            (new Filesystem)->deleteDirectory($temporary);
        }
    }

    public static function backends(): array
    {
        return [['process'], ['extension']];
    }
}
