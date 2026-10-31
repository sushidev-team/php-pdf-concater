<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater;

use InvalidArgumentException;
use JsonException;
use Throwable;

final class Merger
{
    private readonly ?string $binary;

    public function __construct(
        ?string $binary = null,
        private readonly int $threads = 8,
        private readonly int $batchSize = 32,
        private readonly bool $compress = true,
    ) {
        if ($threads < 1 || $batchSize < 1) {
            throw new InvalidArgumentException('threads and batchSize must be positive.');
        }
        $environment = getenv('PDF_CONCATER_BINARY');
        $this->binary = $binary ?? ($environment !== false && $environment !== '' ? $environment : null);
        if ($this->binary !== null) {
            self::checkPath($this->binary);
        }
    }

    public function backend(): string
    {
        return $this->binary === null
            && function_exists('pdf_concater_merge_files')
            && function_exists('pdf_concater_merge_dir') ? 'extension' : 'process';
    }

    /** @param list<string> $files @return array<string, int|float|string> */
    public function merge(array $files, string $output): array
    {
        if ($files === [] || ! array_is_list($files)) {
            throw new InvalidArgumentException('files must be a non-empty list of PDF paths.');
        }
        foreach ($files as $file) {
            if (! is_string($file)) {
                throw new InvalidArgumentException('Each file path must be a string.');
            }
            self::checkPath($file);
            if (! is_file($file) || ! is_readable($file)) {
                throw new MergeException("Input file is not readable: {$file}");
            }
        }
        self::prepareOutput($output);

        return $this->execute('merge-files', $files, $output);
    }

    /** Merge direct PDF files in filename order. @return array<string, int|float|string> */
    public function mergeDirectory(string $directory, string $output): array
    {
        self::checkPath($directory);
        if (! is_dir($directory) || ! is_readable($directory)) {
            throw new MergeException("Input directory is not readable: {$directory}");
        }
        self::prepareOutput($output);

        return $this->execute('merge', $directory, $output);
    }

    private static function checkPath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Paths must be non-empty and contain no null bytes.');
        }
    }

    private static function prepareOutput(string $output): void
    {
        self::checkPath($output);
        $parent = dirname($output);
        if (! is_dir($parent) && ! @mkdir($parent, 0777, true) && ! is_dir($parent)) {
            throw new MergeException("Cannot create output directory: {$parent}");
        }
        if (! is_writable($parent) || is_dir($output)) {
            throw new MergeException("Output path is not writable: {$output}");
        }
    }

    /** @param list<string>|string $input @return array<string, int|float|string> */
    private function execute(string $command, array|string $input, string $output): array
    {
        $started = hrtime(true);
        $engine = $this->compress ? 'streaming-compressed' : 'streaming';
        $backend = $this->backend();
        try {
            if ($backend === 'extension') {
                $result = is_array($input)
                    ? \pdf_concater_merge_files($input, $output, $this->threads, $this->batchSize, $engine)
                    : \pdf_concater_merge_dir($input, $output, $this->threads, $this->batchSize, $engine);
            } else {
                $binary = $this->binary ?? dirname(__DIR__, 2).'/target/release/pdf-concater';
                if (! is_file($binary) || ! is_executable($binary)) {
                    throw new MergeException('Rust executable not found: '.$binary
                        .'. Run composer build in the package, or vendor/bin/pdf-concater-build in your application.');
                }
                $binary = realpath($binary);
                $arguments = [$binary, $command, '--output', $output,
                    '--threads', (string) $this->threads, '--batch-size', (string) $this->batchSize,
                    '--engine', $engine];
                if (is_string($input)) {
                    array_push($arguments, '--dir', $input);
                }
                $result = $this->run($arguments, is_array($input) ? json_encode($input, JSON_THROW_ON_ERROR) : '');
            }
        } catch (MergeException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new MergeException($error->getMessage(), 0, $error);
        }
        $result['backend'] = $backend;
        $result['elapsed_ms'] = (hrtime(true) - $started) / 1_000_000;

        return $result;
    }

    /** @param list<string> $arguments @return array<string, int|float|string> */
    private function run(array $arguments, string $input): array
    {
        if (! function_exists('proc_open')) {
            throw new MergeException('proc_open is unavailable. Enable it or load the pdf_concater extension.');
        }
        // Regular files prevent pipe deadlocks and keep long filename lists out of argv.
        $streams = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $stream = tmpfile();
                if ($stream === false) {
                    throw new MergeException('Cannot create subprocess temporary streams.');
                }
                $streams[] = $stream;
            }
            if ($input !== '' && fwrite($streams[0], $input) !== strlen($input)) {
                throw new MergeException('Cannot write PDF filename list.');
            }
            rewind($streams[0]);
            $process = proc_open($arguments, $streams, $pipes, null, null, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new MergeException('Cannot start the Rust executable.');
            }
            $exitCode = proc_close($process);
            rewind($streams[1]);
            rewind($streams[2]);
            $stdout = stream_get_contents($streams[1], 1_048_576);
            $stderr = stream_get_contents($streams[2], 1_048_576);
            if ($exitCode !== 0) {
                throw new MergeException('PDF merge failed: '.trim($stderr ?: 'Rust process exited with code '.$exitCode));
            }
            try {
                $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new MergeException('Rust returned invalid JSON.', 0, $error);
            }
            if (! is_array($result) || ! isset($result['pages'], $result['files'], $result['total_ms'])) {
                throw new MergeException('Rust returned an unexpected result.');
            }

            return $result;
        } finally {
            foreach ($streams as $stream) {
                fclose($stream);
            }
        }
    }
}
