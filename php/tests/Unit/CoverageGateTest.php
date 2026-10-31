<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Tests;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class CoverageGateTest extends TestCase
{
    #[TestWith(['complete', 0])]
    #[TestWith(['partial', 1])]
    #[TestWith(['uncovered-line', 1])]
    #[TestWith(['empty', 1])]
    #[TestWith(['malformed', 1])]
    #[TestWith(['missing', 1])]
    #[TestWith(['missing-lines', 1])]
    public function test_enforces_complete_coverage_reports(string $case, int $expectedExit): void
    {
        $stream = tmpfile();
        $path = stream_get_meta_data($stream)['uri'];
        $covered = $case === 'partial' ? 1 : 2;
        $lineHits = $case === 'uncovered-line' ? 0 : 1;
        $lines = $case === 'missing-lines' ? '' : '<line num="1" type="stmt" count="1"/><line num="2" type="stmt" count="'.$lineHits.'"/>';
        $report = '<coverage><project><file name="example.php">'.$lines.'<metrics statements="2" coveredstatements="'.$covered.'"/></file><metrics statements="2" coveredstatements="'.$covered.'"/></project></coverage>';
        if ($case === 'empty') {
            $report = '<coverage><project><metrics statements="0" coveredstatements="0"/></project></coverage>';
        } elseif ($case === 'malformed') {
            $report = 'invalid XML';
        }
        fwrite($stream, $report);
        fflush($stream);
        $output = tmpfile();
        $error = tmpfile();
        try {
            $root = dirname(__DIR__, 3);
            $process = proc_open([PHP_BINARY, $root.'/scripts/check-coverage.php', $case === 'missing' ? $path.'.missing' : $path],
                [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $error], $pipes);
            $exit = proc_close($process);

            self::assertSame($expectedExit, $exit);
            rewind($expectedExit === 0 ? $output : $error);
            self::assertNotSame('', stream_get_contents($expectedExit === 0 ? $output : $error));
        } finally {
            fclose($stream);
            fclose($output);
            fclose($error);
        }
    }
}
