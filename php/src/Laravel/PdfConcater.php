<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Laravel;

use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use InvalidArgumentException;
use League\Flysystem\WhitespacePathNormalizer;
use SushiDev\PdfConcater\MergeException;
use SushiDev\PdfConcater\Merger;
use Throwable;

final class PdfConcater
{
    public function __construct(
        private readonly Merger $merger,
        private readonly Factory $filesystems,
        private readonly ?string $temporaryDirectory = null,
    ) {}

    /** @param list<string> $files @return array<string, int|float|string> */
    public function merge(array $files, string $output): array
    {
        return $this->merger->merge($files, $output);
    }

    /** @return array<string, int|float|string> */
    public function mergeDirectory(string $directory, string $output): array
    {
        return $this->merger->mergeDirectory($directory, $output);
    }

    public function backend(): string
    {
        return $this->merger->backend();
    }

    /**
     * @param  list<string>  $files  Paths relative to the source disk, in merge order.
     * @param  array<string, mixed>  $options  Flysystem write options for the destination.
     * @return array<string, int|float|string>
     */
    public function mergeFromDisk(
        array $files,
        string $output,
        ?string $disk = null,
        ?string $outputDisk = null,
        array $options = [],
    ): array {
        $started = hrtime(true);
        if ($files === [] || ! array_is_list($files)) {
            throw new InvalidArgumentException('files must be a non-empty list of disk-relative PDF paths.');
        }
        $normalizer = new WhitespacePathNormalizer;
        $output = $this->normalize($normalizer, $output);
        $paths = [];
        foreach ($files as $file) {
            if (! is_string($file)) {
                throw new InvalidArgumentException('Each disk path must be a string.');
            }
            $paths[] = $this->normalize($normalizer, $file);
        }
        $outputDisk ??= $disk;
        if ($disk === $outputDisk && in_array($output, $paths, true)) {
            throw new MergeException('Output must not overwrite an input PDF.');
        }
        $temporary = null;
        try {
            $source = $this->filesystems->disk($disk);
            $destination = $this->filesystems->disk($outputDisk);
            $localInputs = [];
            $staged = [];
            $stagedBytes = 0;
            foreach ($paths as $index => $path) {
                if ($source instanceof LocalFilesystemAdapter) {
                    $localInputs[] = $source->path($path);
                } else {
                    if (! isset($staged[$path])) {
                        $temporary ??= $this->workspace();
                        $local = $temporary.'/'.$index.'.pdf';
                        $stagedBytes += $this->download($source, $path, $local);
                        $staged[$path] = $local;
                    }
                    $localInputs[] = $staged[$path];
                }
            }
            $inputMs = (hrtime(true) - $started) / 1_000_000;
            $direct = $destination instanceof LocalFilesystemAdapter
                && ! ($destination->getConfig()['read-only'] ?? false) && $options === [];
            if ($direct) {
                $localOutput = $destination->path($output);
            } else {
                $temporary ??= $this->workspace();
                $localOutput = $temporary.'/merged.pdf';
            }
            $result = $this->merger->merge($localInputs, $localOutput);
            $writeStarted = hrtime(true);
            if ($direct) {
                $visibility = $destination->getConfig()['visibility'] ?? 'private';
                if (! $destination->setVisibility($output, $visibility)) {
                    throw new MergeException('Cannot set output visibility.');
                }
            } else {
                $this->upload($destination, $output, $localOutput, $options);
            }
            $result['merge_elapsed_ms'] = $result['elapsed_ms'];
            $result['input_io_ms'] = $inputMs;
            $result['output_io_ms'] = (hrtime(true) - $writeStarted) / 1_000_000;
            $result['elapsed_ms'] = (hrtime(true) - $started) / 1_000_000;
            $result['staged_input_bytes'] = $stagedBytes;
            $result['staged_files'] = count($staged);
            $result['storage_mode'] = $source instanceof LocalFilesystemAdapter && $direct ? 'local' : 'streams';
            $result['path'] = $output;

            return $result;
        } catch (MergeException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new MergeException($error->getMessage(), 0, $error);
        } finally {
            if ($temporary !== null) {
                foreach (glob($temporary.'/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($temporary);
            }
        }
    }

    private function normalize(WhitespacePathNormalizer $normalizer, string $path): string
    {
        $path = $normalizer->normalizePath($path);
        if ($path === '') {
            throw new InvalidArgumentException('Disk paths must not be empty.');
        }

        return $path;
    }

    private function workspace(): string
    {
        $parent = $this->temporaryDirectory ?? sys_get_temp_dir();
        $path = rtrim($parent, '/').'/pdf-concater-'.bin2hex(random_bytes(16));
        if (! @mkdir($path, 0700, true)) {
            throw new MergeException('Cannot create PDF staging directory.');
        }

        return $path;
    }

    private function download(Filesystem $source, string $path, string $local): int
    {
        $input = $source->readStream($path);
        if (! is_resource($input)) {
            throw new MergeException('Cannot read input stream: '.$path);
        }
        $output = null;
        try {
            $output = @fopen($local, 'xb');
            if ($output === false) {
                throw new MergeException('Cannot create staged input file.');
            }
            $bytes = @stream_copy_to_stream($input, $output);
            if ($bytes === false) {
                throw new MergeException('Cannot copy input stream: '.$path);
            }

            return $bytes;
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /** @param array<string, mixed> $options */
    private function upload(Filesystem $destination, string $path, string $local, array $options): void
    {
        $stream = @fopen($local, 'rb');
        if ($stream === false) {
            throw new MergeException('Cannot open merged output stream.');
        }
        try {
            if (! $destination->writeStream($path, $stream, $options)) {
                throw new MergeException('Cannot write output stream: '.$path);
            }
        } finally {
            fclose($stream);
        }
    }
}
