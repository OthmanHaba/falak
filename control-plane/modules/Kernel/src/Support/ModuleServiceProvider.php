<?php

namespace Falak\Kernel\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base provider for every module: loads the module's migrations and routes
 * from modules/<Module>/ by convention.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $path = $this->modulePath();

        $this->loadMigrationsFrom("{$path}/database/migrations");

        if (is_file("{$path}/routes/web.php")) {
            Route::middleware('web')->group("{$path}/routes/web.php");
        }

        if (is_file("{$path}/routes/api.php")) {
            Route::middleware('api')->prefix('api')->group("{$path}/routes/api.php");
        }

        if (is_file("{$path}/routes/agent.php")) {
            Route::prefix('agent/v1')->group("{$path}/routes/agent.php");
        }

        $this->bootModule();
    }

    /**
     * Module-specific boot logic (event listeners, policies, schedules).
     */
    protected function bootModule(): void {}

    protected function modulePath(): string
    {
        return dirname((new ReflectionClass($this))->getFileName(), 2);
    }
}
