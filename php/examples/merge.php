<?php
declare(strict_types=1);

if (!function_exists('pdf_concater_merge_dir')) {
    fwrite(STDERR, "Load the extension with php -d extension=/absolute/path/to/libpdf_concater.dylib (macOS) or .so (Linux).\n");
    exit(1);
}

// Usage: php -d extension=... php/examples/merge.php [input-dir] [output.pdf] [threads] [batch-size] [engine]
$root = dirname(__DIR__, 2);
$input = $argv[1] ?? $root . '/data';
$output = $argv[2] ?? $root . '/output/php-merged.pdf';
$threads = filter_var($argv[3] ?? '8', FILTER_VALIDATE_INT);
$batchSize = filter_var($argv[4] ?? '32', FILTER_VALIDATE_INT);
$engine = $argv[5] ?? 'streaming';
if ($threads === false || $batchSize === false) {
    fwrite(STDERR, "threads and batch-size must be integers.\n");
    exit(1);
}

try {
    $start = hrtime(true);
    $metrics = pdf_concater_merge_dir($input, $output, $threads, $batchSize, $engine);
    $metrics['php_call_ms'] = (hrtime(true) - $start) / 1_000_000;
    // This excludes allocations by the native Rust extension; external RSS measures those.
    $metrics['php_allocator_peak_mib'] = memory_get_peak_usage(true) / 1024 / 1024;
    $metrics['php_version'] = PHP_VERSION;
    echo json_encode($metrics, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
