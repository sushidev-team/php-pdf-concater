<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use SushiDev\PdfConcater\Facades\PdfConcater;
use Tests\TestCase;

class PdfConcaterStorageTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_merges_between_storage_disks_without_staging_local_inputs(): void
    {
        Storage::fake('source');
        Storage::fake('archive');
        config(['pdf-concater.binary' => base_path('../../target/release/pdf-concater')]);
        foreach (['first.pdf', 'second.pdf'] as $file) {
            Storage::disk('source')->put($file, file_get_contents(base_path('tests/Fixtures/'.$file)));
        }

        $result = PdfConcater::mergeFromDisk(
            ['first.pdf', 'second.pdf'],
            'exports/combined.pdf',
            disk: 'source',
            outputDisk: 'archive',
        );

        Storage::disk('archive')->assertExists('exports/combined.pdf');
        $this->assertSame(0, $result['staged_input_bytes']);
        $this->assertSame('local', $result['storage_mode']);
        $this->assertMergedPdfMatchesFixtures(Storage::disk('archive')->path($result['path']));
    }
}
