<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Laravel;

use SushiDev\PdfConcater\Tests\Runtime;

function mkdir(string $path, int $permissions = 0777, bool $recursive = false): bool
{
    return Runtime::$failure === 'storage-mkdir' ? false : \mkdir($path, $permissions, $recursive);
}

function fopen(string $path, string $mode): mixed
{
    if ((Runtime::$failure === 'storage-input-open' && $mode === 'xb')
        || (Runtime::$failure === 'storage-output-open' && $mode === 'rb')) {
        return false;
    }

    return \fopen($path, $mode);
}

function stream_copy_to_stream(mixed $input, mixed $output): int|false
{
    return Runtime::$failure === 'storage-copy' ? false : \stream_copy_to_stream($input, $output);
}
