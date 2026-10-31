<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PDF Concater is registered by its auto-discovered package service provider.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
