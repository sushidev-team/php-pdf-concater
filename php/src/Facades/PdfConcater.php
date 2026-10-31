<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Facades;

use Illuminate\Support\Facades\Facade;
use SushiDev\PdfConcater\Laravel\PdfConcater as Service;

/**
 * @method static array merge(array $files, string $output)
 * @method static array mergeDirectory(string $directory, string $output)
 * @method static string backend()
 * @method static array mergeFromDisk(array $files, string $output, ?string $disk = null, ?string $outputDisk = null, array $options = [])
 *
 * @see Service
 */
final class PdfConcater extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Service::class;
    }
}
