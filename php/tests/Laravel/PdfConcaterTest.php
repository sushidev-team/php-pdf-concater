<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use SushiDev\PdfConcater\Facades\PdfConcater;
use SushiDev\PdfConcater\Laravel\PdfConcater as Service;
use SushiDev\PdfConcater\Merger;
use SushiDev\PdfConcater\PdfConcaterServiceProvider;

final class PdfConcaterTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PdfConcaterServiceProvider::class];
    }

    public function test_resolves_the_same_service_through_dependency_injection_and_the_facade(): void
    {
        $service = $this->app->make(Merger::class);

        self::assertSame($service, $this->app->make(Merger::class));
        self::assertSame($this->app->make(Service::class), PdfConcater::getFacadeRoot());
        self::assertSame($service->backend(), PdfConcater::backend());
        self::assertSame(8, config('pdf-concater.threads'));
        self::assertSame(32, config('pdf-concater.batch_size'));
        self::assertTrue(config('pdf-concater.compress'));
    }

    public function test_merges_real_pdfs_through_the_facade_with_laravel_configuration(): void
    {
        $root = dirname(__DIR__, 3);
        $output = $root.'/tmp/testbench-'.bin2hex(random_bytes(5)).'.pdf';
        config([
            'pdf-concater.binary' => $root.'/target/release/pdf-concater',
            'pdf-concater.threads' => 2,
            'pdf-concater.batch_size' => 1,
            'pdf-concater.compress' => false,
        ]);

        try {
            $result = PdfConcater::merge([
                $root.'/php/tests/Fixtures/second.pdf',
                $root.'/php/tests/Fixtures/first.pdf',
            ], $output);

            self::assertSame(2, $result['pages']);
            self::assertSame(2, $result['threads']);
            self::assertSame(1, $result['batch_size']);
            self::assertSame('streaming', $result['engine']);
            self::assertSame('process', $result['backend']);
            $contents = file_get_contents($output);
            self::assertLessThan(strpos($contents, '(First source)'), strpos($contents, '(Second source)'));
        } finally {
            if (is_file($output)) {
                unlink($output);
            }
        }
    }

    public function test_publishes_the_package_configuration(): void
    {
        $destination = config_path('pdf-concater.php');
        $previous = is_file($destination) ? file_get_contents($destination) : null;
        if ($previous !== null) {
            unlink($destination);
        }

        try {
            $this->artisan('vendor:publish', ['--tag' => 'pdf-concater-config'])
                ->assertSuccessful();

            self::assertFileExists($destination);
            self::assertSame(file_get_contents(dirname(__DIR__, 2).'/config/pdf-concater.php'), file_get_contents($destination));
        } finally {
            if ($previous === null) {
                unlink($destination);
            } else {
                file_put_contents($destination, $previous);
            }
        }
    }
}
