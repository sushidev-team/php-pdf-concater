<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use SushiDev\PdfConcater\Merger;

// Internal CI adapter: ordered file lists arrive on stdin, never through a shell.
try {
    $files = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $merger = new Merger(binary: $argv[1] === 'native' ? null : $argv[1]);
    $result = $merger->merge($files, $argv[2]);
    if ($argv[1] === 'native' && $result['backend'] !== 'extension') {
        throw new RuntimeException('Expected the native PHP backend.');
    }
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
