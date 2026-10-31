<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Facades;

use Illuminate\Support\Facades\Facade;
use SushiDev\PdfConcater\Merger;

/**
 * @method static array merge(array $files, string $output)
 * @method static array mergeDirectory(string $directory, string $output)
 * @method static string backend()
 *
 * @see Merger
 */
final class PdfConcater extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Merger::class;
    }
}
