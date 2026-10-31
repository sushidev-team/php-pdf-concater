<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use SushiDev\PdfConcater\Merger;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php merge.php INPUT_DIRECTORY OUTPUT.pdf\n");
    exit(1);
}

try {
    $result = (new Merger())->mergeDirectory($argv[1], $argv[2]);
    printf("Merged %d PDFs into %d pages in %.1f ms using %s.\nSaved: %s\n",
        $result['files'], $result['pages'], $result['elapsed_ms'], $result['backend'], $argv[2]);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
