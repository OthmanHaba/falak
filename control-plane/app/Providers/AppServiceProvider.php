<?php

namespace App\Providers;

use Falak\Kernel\Support\SharedProps;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registered before the module providers, which add their shared Inertia props to it.
        $this->app->singleton(SharedProps::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
