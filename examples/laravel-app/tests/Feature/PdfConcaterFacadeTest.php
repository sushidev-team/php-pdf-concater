<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use SushiDev\PdfConcater\Facades\PdfConcater;
use SushiDev\PdfConcater\Laravel\PdfConcater as Service;
use Tests\TestCase;

class PdfConcaterFacadeTest extends TestCase
{
    public function test_auto_discovery_registers_the_configured_service_and_facade(): void
    {
        Storage::fake('local');
        config([
            'pdf-concater.binary' => base_path('../../target/release/pdf-concater'),
            'pdf-concater.threads' => 1,
            'pdf-concater.batch_size' => 2,
            'pdf-concater.compress' => false,
        ]);
        $output = Storage::disk('local')->path('facade.pdf');

        $result = PdfConcater::merge([
            base_path('tests/Fixtures/first.pdf'),
            base_path('tests/Fixtures/second.pdf'),
        ], $output);

        $this->assertSame($this->app->make(Service::class), PdfConcater::getFacadeRoot());
        $this->assertSame('process', $result['backend']);
        $this->assertSame(1, $result['threads']);
        $this->assertSame(2, $result['batch_size']);
        $this->assertSame('streaming', $result['engine']);
        $this->assertMergedPdfMatchesFixtures($output);
    }
}
