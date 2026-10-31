<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Tests;

use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use SushiDev\PdfConcater\MergeException;
use SushiDev\PdfConcater\Merger;

final class MergerTest extends TestCase
{
    private string $temporary;

    private string $binary;

    private string $first;

    private string $second;

    private string|false $oldEnvironment;

    protected function setUp(): void
    {
        Runtime::reset();
        $this->oldEnvironment = getenv('PDF_CONCATER_BINARY');
        putenv('PDF_CONCATER_BINARY');
        $root = dirname(__DIR__, 3);
        $this->binary = $root.'/target/release/pdf-concater';
        $this->temporary = $root.'/tmp/phpunit-'.bin2hex(random_bytes(6));
        mkdir($this->temporary.'/input', 0777, true);
        $this->first = $this->temporary.'/input/first.pdf';
        $this->second = $this->temporary.'/input/second.pdf';
        copy(__DIR__.'/../Fixtures/first.pdf', $this->first);
        copy(__DIR__.'/../Fixtures/second.pdf', $this->second);
    }

    protected function tearDown(): void
    {
        Runtime::reset();
        putenv($this->oldEnvironment === false ? 'PDF_CONCATER_BINARY' : 'PDF_CONCATER_BINARY='.$this->oldEnvironment);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temporary);
    }

    private function outputPath(): string
    {
        return $this->temporary.'/output/merged.pdf';
    }

    private function process(bool $compress = true): Merger
    {
        return new Merger(binary: $this->binary, compress: $compress);
    }

    private function executable(string $body, bool $executable = true): string
    {
        $path = $this->temporary.'/backend';
        file_put_contents($path, "#!/usr/bin/env php\n<?php\n".$body."\n");
        chmod($path, $executable ? 0700 : 0600);

        return $path;
    }

    public function test_merges_a_directory_with_the_real_rust_process(): void
    {
        $result = $this->process()->mergeDirectory(dirname($this->first), $this->outputPath());

        self::assertSame(2, $result['files']);
        self::assertSame(2, $result['pages']);
        self::assertSame('process', $result['backend']);
        self::assertSame('streaming-compressed', $result['engine']);
        self::assertGreaterThan(0, $result['elapsed_ms']);
        self::assertFileExists($this->outputPath());
    }

    public function test_preserves_explicit_order_and_duplicate_sources(): void
    {
        $result = $this->process(false)->merge([$this->second, $this->first, $this->second], $this->outputPath());
        $contents = file_get_contents($this->outputPath());

        self::assertSame(3, $result['pages']);
        self::assertSame('streaming', $result['engine']);
        self::assertSame(2, substr_count($contents, '(Second source)'));
        self::assertLessThan(strpos($contents, '(First source)'), strpos($contents, '(Second source)'));
    }

    public function test_environment_configures_the_rust_executable(): void
    {
        putenv('PDF_CONCATER_BINARY='.$this->binary);

        $result = (new Merger)->merge([$this->first], $this->outputPath());

        self::assertSame('process', $result['backend']);
        self::assertSame(1, $result['pages']);
    }

    public function test_default_executable_is_used_if_the_extension_is_unavailable(): void
    {
        Runtime::$unavailable = ['pdf_concater_merge_dir'];

        $result = (new Merger)->merge([$this->first], $this->outputPath());

        self::assertSame('process', $result['backend']);
        self::assertSame(1, $result['pages']);
    }

    public function test_uses_both_real_native_extension_functions(): void
    {
        if (! extension_loaded('pdf_concater')) {
            self::markTestSkipped('Load the native extension to test this optional backend.');
        }
        $merger = new Merger;

        $directory = $merger->mergeDirectory(dirname($this->first), $this->outputPath());
        $files = $merger->merge([$this->second, $this->first], $this->outputPath());

        self::assertSame('extension', $directory['backend']);
        self::assertSame('extension', $files['backend']);
        self::assertSame(2, $files['pages']);
    }

    public function test_wraps_a_real_native_merge_failure_and_preserves_previous_output(): void
    {
        if (! extension_loaded('pdf_concater')) {
            self::markTestSkipped('Load the native extension to test this optional backend.');
        }
        $merger = new Merger;
        $merger->merge([$this->first], $this->outputPath());
        $previous = file_get_contents($this->outputPath());
        $invalid = $this->temporary.'/invalid.pdf';
        file_put_contents($invalid, 'not a PDF');

        try {
            $merger->merge([$invalid], $this->outputPath());
            self::fail('Expected a merge error');
        } catch (MergeException $error) {
            self::assertStringContainsString('PDF merge failed', $error->getMessage());
            self::assertNotNull($error->getPrevious());
        }

        self::assertSame($previous, file_get_contents($this->outputPath()));
    }

    #[TestWith([0, 32])]
    #[TestWith([8, 0])]
    #[TestWith([-1, 32])]
    public function test_rejects_nonpositive_worker_options(int $threads, int $batch): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Merger(threads: $threads, batchSize: $batch);
    }

    #[TestWith([[]])]
    #[TestWith([['named' => 'file.pdf']])]
    #[TestWith([[null]])]
    #[TestWith([['']])]
    #[TestWith([["bad\0path"]])]
    public function test_rejects_invalid_input_lists(array $files): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->process()->merge($files, $this->outputPath());
    }

    public function test_rejects_an_invalid_configured_binary_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Merger(binary: '');
    }

    public function test_rejects_a_missing_input_file(): void
    {
        $this->expectExceptionMessage('Input file is not readable');
        $this->process()->merge([$this->temporary.'/missing.pdf'], $this->outputPath());
    }

    public function test_rejects_an_unreadable_input_file(): void
    {
        chmod($this->first, 0000);
        $this->expectExceptionMessage('Input file is not readable');
        $this->process()->merge([$this->first], $this->outputPath());
    }

    public function test_rejects_a_missing_input_directory(): void
    {
        $this->expectExceptionMessage('Input directory is not readable');
        $this->process()->mergeDirectory($this->temporary.'/missing', $this->outputPath());
    }

    public function test_rejects_an_invalid_output_path(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->process()->merge([$this->first], '');
    }

    public function test_reports_a_blocked_output_directory(): void
    {
        $blocker = $this->temporary.'/blocker';
        file_put_contents($blocker, 'file in place of a directory');
        $this->expectExceptionMessage('Cannot create output directory');
        $this->process()->merge([$this->first], $blocker.'/merged.pdf');
    }

    public function test_does_not_replace_an_output_directory(): void
    {
        $this->expectExceptionMessage('Output path is not writable');
        $this->process()->merge([$this->first], $this->temporary);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_reports_a_missing_or_nonexecutable_binary(bool $exists): void
    {
        $binary = $exists ? $this->executable('echo "unused";', false) : $this->temporary.'/missing';
        $this->expectExceptionMessage('Rust executable not found');
        (new Merger(binary: $binary))->merge([$this->first], $this->outputPath());
    }

    public function test_reports_a_disabled_process_api(): void
    {
        Runtime::$unavailable = ['proc_open'];
        $this->expectExceptionMessage('proc_open is unavailable');
        $this->process()->merge([$this->first], $this->outputPath());
    }

    #[TestWith(['tmpfile', 'Cannot create subprocess temporary streams'])]
    #[TestWith(['write', 'Cannot write PDF filename list'])]
    #[TestWith(['start', 'Cannot start the Rust executable'])]
    public function test_reports_os_failures_and_closes_temporary_streams(string $failure, string $message): void
    {
        Runtime::$failure = $failure;
        $before = count(get_resources('stream'));

        try {
            $this->process()->merge([$this->first], $this->outputPath());
            self::fail('Expected an OS failure');
        } catch (MergeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }

        self::assertSame($before, count(get_resources('stream')));
        self::assertFileDoesNotExist($this->outputPath());
    }

    public function test_wraps_filename_list_encoding_errors(): void
    {
        Runtime::$failure = 'json';

        try {
            $this->process()->merge([$this->first], $this->outputPath());
            self::fail('Expected an invalid UTF-8 error');
        } catch (MergeException $error) {
            self::assertInstanceOf(JsonException::class, $error->getPrevious());
        }
    }

    #[TestWith(['fwrite(STDERR, "backend failure"); exit(9);', 'backend failure'])]
    #[TestWith(['exit(9);', 'exited with code 9'])]
    #[TestWith(['echo "not JSON";', 'invalid JSON'])]
    #[TestWith(['echo "null";', 'unexpected result'])]
    #[TestWith(['echo "{}";', 'unexpected result'])]
    public function test_rejects_invalid_backend_responses(string $body, string $message): void
    {
        $binary = $this->executable($body);
        $this->expectExceptionMessage($message);
        (new Merger(binary: $binary))->merge([$this->first], $this->outputPath());
    }
}
