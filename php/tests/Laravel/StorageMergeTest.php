<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Tests\Laravel;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SushiDev\PdfConcater\Facades\PdfConcater;
use SushiDev\PdfConcater\Laravel\PdfConcater as Service;
use SushiDev\PdfConcater\MergeException;
use SushiDev\PdfConcater\Merger;
use SushiDev\PdfConcater\PdfConcaterServiceProvider;
use SushiDev\PdfConcater\Tests\Runtime;

final class StorageMergeTest extends TestCase
{
    private string $directory;

    private string $root;

    protected function getPackageProviders($app): array
    {
        return [PdfConcaterServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Runtime::reset();
        $this->root = dirname(__DIR__, 3);
        $this->directory = $this->root.'/tmp/storage-test-'.bin2hex(random_bytes(6));
        config([
            'pdf-concater.binary' => $this->root.'/target/release/pdf-concater',
            'pdf-concater.temporary_directory' => $this->directory.'/staging',
            'filesystems.default' => 'source',
            'filesystems.disks.source' => ['driver' => 'local', 'root' => $this->directory.'/source', 'throw' => true],
            'filesystems.disks.destination' => ['driver' => 'local', 'root' => $this->directory.'/destination', 'throw' => true, 'visibility' => 'public'],
        ]);
        Storage::disk('source')->put('first.pdf', file_get_contents($this->root.'/php/tests/Fixtures/first.pdf'));
        Storage::disk('source')->put('second.pdf', file_get_contents($this->root.'/php/tests/Fixtures/second.pdf'));
    }

    protected function tearDown(): void
    {
        Runtime::reset();
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_local_disks_use_direct_paths_without_staging_and_keep_order(): void
    {
        $result = PdfConcater::mergeFromDisk(['second.pdf', 'first.pdf', 'second.pdf'], 'exports/result.pdf',
            disk: 'source', outputDisk: 'destination');
        self::assertSame('local', $result['storage_mode']);
        self::assertSame(0, $result['staged_files']);
        self::assertSame(0, $result['staged_input_bytes']);
        self::assertSame(3, $result['pages']);
        self::assertSame('exports/result.pdf', $result['path']);
        self::assertGreaterThanOrEqual($result['merge_elapsed_ms'], $result['elapsed_ms']);
        self::assertSame('public', Storage::disk('destination')->getVisibility($result['path']));
        self::assertDirectoryDoesNotExist($this->directory.'/staging');
        $bytes = Storage::disk('destination')->get($result['path']);
        self::assertSame(2, substr_count($bytes, '(Second source)'));
        self::assertLessThan(strpos($bytes, '(First source)'), strpos($bytes, '(Second source)'));
        self::assertSame(2, PdfConcater::mergeDirectory(Storage::disk('source')->path(''), $this->directory.'/directory.pdf')['pages']);
        // The configured default disk and default private visibility work too.
        $default = PdfConcater::mergeFromDisk(['first.pdf', 'second.pdf'], 'default.pdf');
        self::assertSame(2, $default['pages']);
        self::assertSame('private', Storage::getVisibility('default.pdf'));
    }

    public function test_streamed_disks_cache_repeated_sources_close_streams_and_clean_staging(): void
    {
        $source = Mockery::mock(Filesystem::class);
        $destination = Mockery::mock(Filesystem::class);
        $opened = [];
        foreach (['first', 'second'] as $name) {
            $source->shouldReceive('readStream')->once()->with($name.'.pdf')->andReturnUsing(function () use (&$opened, $name) {
                $stream = fopen($this->root.'/php/tests/Fixtures/'.$name.'.pdf', 'rb');
                $opened[] = $stream;

                return $stream;
            });
        }
        $output = '';
        $writtenStream = null;
        $destination->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $stream, $options) use (&$output, &$writtenStream) {
            self::assertSame('result.pdf', $path);
            self::assertSame(['visibility' => 'private', 'ContentType' => 'application/pdf'], $options);
            $writtenStream = $stream;
            $output = stream_get_contents($stream);

            return true;
        });
        $service = $this->remoteService($source, $destination);
        $result = $service->mergeFromDisk(['second.pdf', 'first.pdf', 'second.pdf'], 'result.pdf',
            disk: 'remote', outputDisk: 'archive', options: ['visibility' => 'private', 'ContentType' => 'application/pdf']);
        self::assertSame(3, $result['pages']);
        self::assertSame(2, $result['staged_files']);
        self::assertSame(filesize($this->root.'/php/tests/Fixtures/first.pdf') + filesize($this->root.'/php/tests/Fixtures/second.pdf'), $result['staged_input_bytes']);
        self::assertSame('streams', $result['storage_mode']);
        self::assertSame(2, substr_count($output, '(Second source)'));
        foreach ([...$opened, $writtenStream] as $stream) {
            self::assertFalse(is_resource($stream));
        }
        self::assertSame([], glob($this->directory.'/staging/*'));
    }

    public function test_local_source_can_stream_to_a_remote_disk_and_remote_source_to_local(): void
    {
        $remote = Mockery::mock(Filesystem::class);
        $bytes = '';
        $remote->shouldReceive('writeStream')->once()->andReturnUsing(function ($path, $stream) use (&$bytes) {
            $bytes = stream_get_contents($stream);

            return true;
        });
        $local = Storage::disk('source');
        $service = $this->remoteService($local, $remote);
        $result = $service->mergeFromDisk(['first.pdf', 'second.pdf'], 'remote.pdf', disk: 'remote', outputDisk: 'archive');
        self::assertSame(0, $result['staged_input_bytes']);
        self::assertStringStartsWith('%PDF-', $bytes);
        $remote->shouldReceive('readStream')->once()->with('remote.pdf')->andReturnUsing(function () use ($bytes) {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, $bytes);
            rewind($stream);

            return $stream;
        });
        $result = $this->remoteService($remote, Storage::disk('destination'))
            ->mergeFromDisk(['remote.pdf'], 'back.pdf', disk: 'remote', outputDisk: 'archive');
        self::assertSame(2, $result['pages']);
        $back = Storage::disk('destination')->get('back.pdf');
        self::assertStringContainsString('(First source)', $back);
        self::assertStringContainsString('(Second source)', $back);
        self::assertLessThan(strpos($back, '(Second source)'), strpos($back, '(First source)'));
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_disk_lists_and_empty_paths(array $files, string $output): void
    {
        $this->expectException(InvalidArgumentException::class);
        PdfConcater::mergeFromDisk($files, $output);
    }

    public static function invalidInputs(): array
    {
        return [[[], 'out.pdf'], [['named' => 'first.pdf'], 'out.pdf'], [[123], 'out.pdf'], [[''], 'out.pdf'], [['first.pdf'], '']];
    }

    public function test_normalized_output_cannot_overwrite_a_source(): void
    {
        $before = Storage::get('first.pdf');
        try {
            PdfConcater::mergeFromDisk(['first.pdf'], 'folder/../first.pdf');
            self::fail('Expected collision rejection.');
        } catch (MergeException $error) {
            self::assertStringContainsString('overwrite an input', $error->getMessage());
        }
        self::assertSame($before, Storage::get('first.pdf'));
    }

    #[DataProvider('streamFailures')]
    public function test_stream_failures_close_resources_and_clean_temporary_files(string $failure, string $message): void
    {
        $source = Mockery::mock(Filesystem::class);
        $destination = Mockery::mock(Filesystem::class);
        $opened = null;
        $source->shouldReceive('readStream')->andReturnUsing(function () use ($failure, &$opened) {
            if ($failure === 'read-false') {
                return false;
            }
            if ($failure === 'read-throws') {
                throw new \RuntimeException('remote download failed');
            }
            $opened = fopen($this->root.'/php/tests/Fixtures/first.pdf', 'rb');

            return $opened;
        });
        $written = null;
        $destination->shouldReceive('writeStream')->andReturnUsing(function ($path, $stream) use (&$written) {
            $written = $stream;

            return false;
        });
        Runtime::$failure = $failure;
        try {
            $this->remoteService($source, $destination)->mergeFromDisk(['first.pdf'], 'out.pdf', disk: 'remote', outputDisk: 'archive');
            self::fail('Expected stream failure.');
        } catch (MergeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
            if ($failure === 'read-throws') {
                self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            }
        }
        self::assertFalse(is_resource($opened));
        self::assertFalse(is_resource($written));
        self::assertSame([], glob($this->directory.'/staging/pdf-concater-*') ?: []);
    }

    public static function streamFailures(): array
    {
        return [
            ['storage-mkdir', 'staging directory'], ['read-false', 'Cannot read input stream'],
            ['read-throws', 'remote download failed'], ['storage-input-open', 'Cannot create staged input'],
            ['storage-copy', 'Cannot copy input stream'], ['storage-output-open', 'Cannot open merged output'],
            ['upload-false', 'Cannot write output stream'],
        ];
    }

    public function test_merge_failure_preserves_remote_output_and_cleans_staging(): void
    {
        $source = Mockery::mock(Filesystem::class);
        $source->shouldReceive('readStream')->once()->andReturnUsing(function () {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'not a PDF');
            rewind($stream);

            return $stream;
        });
        $destination = Mockery::mock(Filesystem::class);
        $destination->shouldNotReceive('writeStream');
        try {
            $this->remoteService($source, $destination)->mergeFromDisk(['invalid.pdf'], 'out.pdf', disk: 'remote', outputDisk: 'archive');
            self::fail('Expected invalid PDF rejection.');
        } catch (MergeException $error) {
            self::assertStringContainsString('PDF merge failed', $error->getMessage());
        }
        self::assertSame([], glob($this->directory.'/staging/*'));
    }

    public function test_visibility_failure_is_reported_and_read_only_disks_use_their_writer(): void
    {
        foreach ([false, true] as $readOnly) {
            $destination = Mockery::mock(LocalFilesystemAdapter::class);
            $destination->shouldReceive('getConfig')->andReturn(['read-only' => $readOnly]);
            $destination->shouldReceive('path')->andReturn($this->directory.'/out.pdf');
            $destination->shouldReceive('setVisibility')->andReturn(false);
            $destination->shouldReceive('writeStream')->andReturn(false);
            try {
                $this->remoteService(Storage::disk('source'), $destination)
                    ->mergeFromDisk(['first.pdf'], 'out.pdf', disk: 'remote', outputDisk: 'archive');
                self::fail('Expected destination rejection.');
            } catch (MergeException $error) {
                self::assertStringContainsString($readOnly ? 'write output stream' : 'output visibility', $error->getMessage());
            }
        }
    }

    public function test_optional_staging_directory_defaults_to_system_temporary_storage(): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('disk')->andReturn(Storage::disk('source'));
        $service = new Service($this->app->make(Merger::class), $factory);
        $result = $service->mergeFromDisk(['first.pdf'], 'options.pdf', options: ['visibility' => 'private']);
        self::assertSame(1, $result['pages']);
        self::assertSame('streams', $result['storage_mode']);
    }

    private function remoteService(Filesystem $source, Filesystem $destination): Service
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('disk')->with('remote')->andReturn($source);
        $factory->shouldReceive('disk')->with('archive')->andReturn($destination);

        return new Service($this->app->make(Merger::class), $factory, $this->directory.'/staging');
    }
}
