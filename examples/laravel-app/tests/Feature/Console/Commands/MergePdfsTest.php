<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MergePdfsTest extends TestCase
{
    public function test_creates_a_real_pdf_from_a_directory_through_the_composer_package(): void
    {
        Storage::fake('local');
        config(['pdf-concater.binary' => base_path('../../target/release/pdf-concater')]);
        $output = Storage::disk('local')->path('merged.pdf');

        $this->artisan('pdf:merge', ['input' => base_path('tests/Fixtures'), 'output' => $output, '--json' => true])
            ->expectsOutputToContain('"pages":2')->assertSuccessful();

        $this->assertMergedPdfMatchesFixtures($output);
    }

    public function test_reports_a_missing_input_directory_and_returns_failure(): void
    {
        Storage::fake('local');
        $output = Storage::disk('local')->path('merged.pdf');

        $this->artisan('pdf:merge', ['input' => base_path('missing-input'), 'output' => $output])
            ->expectsOutputToContain('Input directory is not readable')->assertFailed();

        Storage::disk('local')->assertMissing('merged.pdf');
    }

    public function test_reports_page_counts_without_json_output(): void
    {
        Storage::fake('local');
        config(['pdf-concater.binary' => base_path('../../target/release/pdf-concater')]);
        $output = Storage::disk('local')->path('merged.pdf');

        $this->artisan('pdf:merge', ['input' => base_path('tests/Fixtures'), 'output' => $output])
            ->expectsOutputToContain('Merged 2 PDFs into 2 pages')
            ->expectsOutputToContain('Saved: '.$output)
            ->assertSuccessful();

        Storage::disk('local')->assertExists('merged.pdf');
    }
}
