<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater;

use SushiDev\PdfConcater\Tests\Runtime;

function function_exists(string $name): bool
{
    return ! in_array($name, Runtime::$unavailable, true) && \function_exists($name);
}

function tmpfile(): mixed
{
    Runtime::$temporaryCalls++;
    if (Runtime::$failure === 'tmpfile' && Runtime::$temporaryCalls === 2) {
        return false;
    }

    return \tmpfile();
}

function fwrite(mixed $stream, string $bytes): int|false
{
    return Runtime::$failure === 'write' ? false : \fwrite($stream, $bytes);
}

function json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    return \json_encode(Runtime::$failure === 'json' ? ["\xFF"] : $value, $flags, $depth);
}

function proc_open(array|string $command, array $descriptors, mixed &$pipes, ?string $cwd = null, ?array $env = null, array $options = []): mixed
{
    if (Runtime::$failure === 'start') {
        return false;
    }

    return \proc_open($command, $descriptors, $pipes, $cwd, $env, $options);
}
