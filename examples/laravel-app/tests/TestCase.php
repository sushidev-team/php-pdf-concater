<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Symfony\Component\Process\Process;

abstract class TestCase extends BaseTestCase
{
    protected function assertMergedPdfMatchesFixtures(string $output): void
    {
        $this->assertFileExists($output);
        $process = new Process([
            base_path('../../target/release/pdf-concater'), 'verify-content',
            '--dir', base_path('tests/Fixtures'), '--input', $output,
        ]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }
}
