<?php

declare(strict_types=1);

// Fail closed: missing, empty, malformed and partially covered reports must all fail CI.
if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php CLOVER.xml [CLOVER.xml ...]\n");
    exit(1);
}

foreach (array_slice($argv, 1) as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, "Missing coverage report: {$path}\n");
        exit(1);
    }
    libxml_use_internal_errors(true);
    $report = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
    if ($report === false || ! isset($report->project->metrics)) {
        fwrite(STDERR, "Invalid Clover report: {$path}\n");
        exit(1);
    }
    $total = 0;
    $covered = 0;
    $files = $report->xpath('//file');
    foreach ($files as $file) {
        $statements = (int) $file->metrics['statements'];
        $coveredStatements = (int) $file->metrics['coveredstatements'];
        $total += $statements;
        $covered += $coveredStatements;
        if ($statements !== $coveredStatements || $statements < 0) {
            fwrite(STDERR, sprintf("Coverage below 100%%: %s (%d/%d lines)\n", $file['name'], $coveredStatements, $statements));
            exit(1);
        }
        $executableLines = 0;
        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }
            $executableLines++;
            if ((int) $line['count'] < 1) {
                fwrite(STDERR, sprintf("Uncovered line: %s:%s\n", $file['name'], $line['num']));
                exit(1);
            }
        }
        if ($executableLines !== $statements) {
            fwrite(STDERR, "Missing or inconsistent line data in coverage report: {$path}\n");
            exit(1);
        }
    }
    $metrics = $report->project->metrics;
    if ($total < 1 || $total !== $covered || $total !== (int) $metrics['statements'] || $covered !== (int) $metrics['coveredstatements']) {
        fwrite(STDERR, "Empty or inconsistent coverage report: {$path}\n");
        exit(1);
    }
    printf("100%% line coverage: %s (%d/%d executable lines)\n", $path, $covered, $total);
}
