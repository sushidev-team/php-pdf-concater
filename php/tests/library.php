<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SushiDev\PdfConcater\Merger;
use SushiDev\PdfConcater\MergeException;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fails(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (InvalidArgumentException | MergeException $error) {
        check(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected error containing: ' . $message);
}

function fixture(string $path, string $label): void
{
    $text = "BT /F1 12 Tf 50 790 Td ($label) Tj ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> >>',
        '<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($text) . ">>\nstream\n" . $text . 'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n$object\nendobj\n";
    }
    $start = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
    file_put_contents($path, $pdf);
}

$package = dirname(__DIR__, 2);
$root = $package . '/tmp/library-' . bin2hex(random_bytes(5));
mkdir($root . '/input with spaces', 0777, true);
$input = $root . '/input with spaces';
$output = $root . '/merged.pdf';
$binary = $package . '/target/release/pdf-concater';

try {
    fixture($input . '/001.pdf', 'First source');
    fixture($input . '/002.pdf', 'Second source');
    // These characters are filesystem input, never shell code.
    $unusual = $input . '/003 ; echo unexpected $.pdf';
    fixture($unusual, 'Third source');
    $mergers = [new Merger(binary: $binary, compress: false)];
    if (function_exists('pdf_concater_merge_dir')) {
        $mergers[] = new Merger(compress: false);
    }
    foreach ($mergers as $merger) {
        $result = $merger->mergeDirectory($input, $output);
        check($result['files'] === 3 && $result['pages'] === 3, 'Incorrect directory merge');
        check($result['backend'] === $merger->backend(), 'Incorrect backend');
        check($result['elapsed_ms'] > 0, 'Missing elapsed time');
        $result = $merger->merge([$unusual, $input . '/001.pdf', $unusual], $output);
        check($result['pages'] === 3, 'Incorrect ordered-list page count');
        $bytes = file_get_contents($output);
        check(substr_count($bytes, '(Third source)') === 2, 'Repeated file lost');
        check(strpos($bytes, '(Third source)') < strpos($bytes, '(First source)'), 'File order lost');
        fails(fn() => $merger->merge([], $output), 'non-empty list');
        fails(fn() => $merger->merge(['name' => $unusual], $output), 'non-empty list');
        fails(fn() => $merger->merge([null], $output), 'string');
        fails(fn() => $merger->merge(["bad\0name"], $output), 'null bytes');
        fails(fn() => $merger->merge([$input . '/missing.pdf'], $output), 'not readable');
        fails(fn() => $merger->mergeDirectory($input, $input . '/output.pdf'), 'outside the input');
        fails(fn() => $merger->merge([$unusual], $unusual), 'overwrite an input');
        $hash = hash_file('sha256', $output);
        file_put_contents($root . '/bad.pdf', 'not a PDF');
        fails(fn() => $merger->merge([$root . '/bad.pdf'], $output), 'PDF merge failed');
        check(hash_file('sha256', $output) === $hash, 'Existing output was damaged');
        check($merger->mergeDirectory($input, $output)['pages'] === 3, 'Cannot recover after error');
    }
    $compressed = (new Merger(binary: $binary))->merge([$unusual, $unusual], $output);
    check($compressed['engine'] === 'streaming-compressed', 'Compression must default to enabled');
    // A long list goes through stdin instead of hitting command-line argument limits.
    $long = (new Merger(binary: $binary))->merge(array_fill(0, 10000, $unusual), $output);
    check($long['files'] === 10000 && $long['pages'] === 10000, 'Large filename list failed');
    fails(fn() => (new Merger(binary: $root . '/missing'))->merge([$unusual], $output), 'executable not found');
    fails(fn() => new Merger(threads: 0), 'positive');
    echo 'Composer library tests passed for ' . count($mergers)
        . " backend(s): autoload, directories, ordered lists, 10,000 paths, unusual filenames, compression and errors.\n";
} finally {
    foreach (glob($input . '/*') ?: [] as $path) {
        unlink($path);
    }
    foreach (glob($root . '/*') ?: [] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($input);
    rmdir($root);
}
