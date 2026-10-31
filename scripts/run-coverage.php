<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$suite = $argv[1] ?? 'all';
if (! in_array($suite, ['library', 'laravel', 'all'], true)) {
    fwrite(STDERR, "Usage: php scripts/run-coverage.php [library|laravel|all]\n");
    exit(1);
}
if (! extension_loaded('pcov') && ! extension_loaded('xdebug')) {
    fwrite(STDERR, "Coverage requires PCOV or Xdebug with coverage enabled.\n");
    exit(1);
}

function run(array $command, string $directory): void
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory, null, ['bypass_shell' => true]);
    if (! is_resource($process)) {
        fwrite(STDERR, "Cannot start coverage command.\n");
        exit(1);
    }
    $code = proc_close($process);
    if ($code !== 0) {
        exit($code > 0 ? $code : 1);
    }
}

foreach ($suite === 'all' ? ['library', 'laravel'] : [$suite] as $name) {
    $app = $name === 'library' ? $root : $root.'/examples/laravel-app';
    $reportDir = $root.'/coverage/'.$name;
    if (! is_dir($reportDir) && ! mkdir($reportDir, 0777, true) && ! is_dir($reportDir)) {
        throw new RuntimeException('Cannot create coverage directory.');
    }
    $report = $reportDir.'/clover.xml';
    if (is_file($report)) {
        unlink($report);
    }
    $command = [PHP_BINARY, '-d', 'pcov.enabled=1', '-d', 'pcov.directory='.($name === 'library' ? $root.'/php/src' : $app.'/app')];
    if ($name === 'library' && ! extension_loaded('pdf_concater')) {
        $extension = $root.'/target/release/libpdf_concater.'.(PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so');
        if (! is_file($extension)) {
            fwrite(STDERR, "Full library coverage requires the native backend too. Run cargo build --release --locked -p php-pdf-concater first.\n");
            exit(1);
        }
        array_push($command, '-d', 'extension='.$extension);
    }
    putenv('XDEBUG_MODE=coverage');
    array_push($command, $app.'/vendor/bin/phpunit', '-c', $app.'/phpunit.xml',
        '--coverage-clover', $report, '--coverage-html', $reportDir.'/html', '--coverage-text');
    run($command, $app);
    run([PHP_BINARY, $root.'/scripts/check-coverage.php', $report], $root);
}
