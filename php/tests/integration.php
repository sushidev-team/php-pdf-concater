<?php
declare(strict_types=1);

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectFailure(callable $call, string $message): void
{
    try {
        $call();
    } catch (Throwable $error) {
        check(str_contains($error->getMessage(), $message), 'Unexpected error: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

// Independent minimal fixtures: inherited page resources and distinct source object IDs.
function fixture(string $path, string $label): void
{
    $content = "BT /F1 14 Tf 50 790 Td ($label) Tj ET\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> >>',
        '<< /Type /Page /Parent 2 0 R /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        '<< /Length ' . strlen($content) . ">>\nstream\n" . $content . 'endstream',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($path, $pdf);
}

check(extension_loaded('pdf_concater'), 'pdf_concater extension is not loaded');
$root = dirname(__DIR__, 2) . '/tmp/php-test-' . bin2hex(random_bytes(5));
mkdir($root . '/inputs with spaces', 0777, true);
$input = $root . '/inputs with spaces';
$output = $root . '/merged with spaces.pdf';

try {
    fixture($input . '/001.pdf', 'First source');
    fixture($input . '/002.pdf', 'Second source');
    $metrics = pdf_concater_merge_dir($input, $output);
    check($metrics['files'] === 2 && $metrics['pages'] === 2, 'Incorrect directory merge counts');
    check($metrics['threads'] === 8 && $metrics['batch_size'] === 32, 'Incorrect native defaults');
    check($metrics['engine'] === 'streaming', 'Streaming must be the native default');
    check($metrics['output_bytes'] === filesize($output), 'Incorrect output size');
    check(is_float($metrics['total_ms']) && $metrics['total_ms'] > 0, 'Invalid timing');

    $list = [$input . '/002.pdf', $input . '/001.pdf', $input . '/002.pdf'];
    $metrics = pdf_concater_merge_files($list, $output, threads: 2, batch_size: 1);
    check($metrics['files'] === 3 && $metrics['pages'] === 3, 'Incorrect explicit-list merge counts');
    $pdf = file_get_contents($output);
    check(substr_count($pdf, '(Second source)') === 2, 'Repeated input lost');
    $second = strpos($pdf, '(Second source)');
    $first = strpos($pdf, '(First source)');
    check($second < $first, 'Explicit list order lost');

    expectFailure(fn() => pdf_concater_merge_files([], $output), 'must not be empty');
    expectFailure(fn() => pdf_concater_merge_files($list, $output, threads: 0), 'must be positive');
    expectFailure(fn() => pdf_concater_merge_files($list, $output, engine: 'invalid'), 'engine must');
    expectFailure(fn() => pdf_concater_merge_dir($input, $output, threads: 0), 'must be positive');
    expectFailure(fn() => pdf_concater_merge_dir($input, $output, batch_size: -1), 'must be positive');
    expectFailure(fn() => pdf_concater_merge_dir($input, $output, engine: 'invalid'), 'engine must');
    check(pdf_concater_merge_dir($input, $output, engine: 'memory')['pages'] === 2, 'Memory comparison engine failed');
    check(pdf_concater_merge_dir($input, $output, engine: 'streaming-compressed')['pages'] === 2, 'Compressed streaming engine failed');
    expectFailure(fn() => pdf_concater_merge_dir($input, $input . '/out.pdf'), 'outside the input');
    expectFailure(fn() => pdf_concater_merge_files([$input . '/001.pdf'], $input . '/001.pdf'), 'overwrite an input');
    expectFailure(fn() => pdf_concater_merge_files([['invalid']], $output), 'files');
    expectFailure(fn() => pdf_concater_merge_files(["bad\0path"], $output), 'null bytes');
    expectFailure(fn() => pdf_concater_merge_dir("bad\0path", $output), 'null bytes');
    $before = hash_file('sha256', $output);
    file_put_contents($root . '/bad.pdf', 'not a PDF');
    expectFailure(fn() => pdf_concater_merge_files([$root . '/bad.pdf'], $output), 'PDF merge failed');
    check(hash_file('sha256', $output) === $before, 'Failure damaged existing output');
    // An exception must not poison later calls in the same PHP worker.
    check(pdf_concater_merge_dir($input, $output, 1)['pages'] === 2, 'Merge after failure did not recover');
    ini_set('open_basedir', $root);
    expectFailure(fn() => @pdf_concater_merge_dir(dirname($root), $output), 'open_basedir');
    expectFailure(fn() => @pdf_concater_merge_files([dirname($root).'/outside.pdf'], $output), 'open_basedir');
    echo "PHP integration tests passed: native defaults, ordered lists, repeated sources, paths with spaces, errors, output preservation, recovery and open_basedir.\n";
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
