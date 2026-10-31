<?php

declare(strict_types=1);

namespace SushiDev\PdfConcater;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class PdfConcaterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pdf-concater.php', 'pdf-concater');

        $this->app->singleton(Merger::class, static function (Application $app): Merger {
            $config = $app['config'];

            return new Merger(
                binary: $config->get('pdf-concater.binary'),
                threads: (int) $config->get('pdf-concater.threads', 8),
                batchSize: (int) $config->get('pdf-concater.batch_size', 32),
                compress: (bool) $config->get('pdf-concater.compress', true),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/pdf-concater.php' => config_path('pdf-concater.php'),
            ], 'pdf-concater-config');
        }
    }
}
