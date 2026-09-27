<?php

use Illuminate\Support\Facades\Route;
use Kiln\Sites\Http\Controllers\DeployScriptController;
use Kiln\Sites\Http\Controllers\EnvironmentController;
use Kiln\Sites\Http\Controllers\SiteCommandController;
use Kiln\Sites\Http\Controllers\SiteController;
use Kiln\Sites\Http\Controllers\SiteSettingsController;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
    Route::get('sites/create', [SiteController::class, 'create'])->name('sites.create');
    Route::get('sites/search', [SiteController::class, 'search'])->name('sites.search');
    Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
    Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');

    Route::get('sites/{site}/settings', [SiteSettingsController::class, 'show'])->name('sites.settings');
    Route::patch('sites/{site}', [SiteSettingsController::class, 'update'])->name('sites.update');
    Route::put('sites/{site}/targets', [SiteSettingsController::class, 'updateTargets'])->name('sites.targets.update');
    Route::post('sites/{site}/targets/{target}/retry', [SiteSettingsController::class, 'retryTarget'])->name('sites.targets.retry');
    Route::put('sites/{site}/shared-paths', [SiteSettingsController::class, 'sharedPaths'])->name('sites.shared-paths.update');
    Route::put('sites/{site}/laravel', [SiteSettingsController::class, 'laravel'])->name('sites.laravel.update');

    Route::get('sites/{site}/environment', [EnvironmentController::class, 'show'])->name('sites.environment');
    Route::put('sites/{site}/environment', [EnvironmentController::class, 'update'])->name('sites.environment.update');
    Route::patch('sites/{site}/environment', [EnvironmentController::class, 'patch'])->name('sites.environment.patch');
    Route::post('sites/{site}/environment/reveal', [EnvironmentController::class, 'reveal'])->middleware('throttle:30,1')->name('sites.environment.reveal');
    Route::post('sites/{site}/environment/versions/{version}/restore', [EnvironmentController::class, 'restore'])->whereNumber('version')->name('sites.environment.restore');

    Route::get('sites/{site}/deploy-script', [DeployScriptController::class, 'show'])->name('sites.deploy-script');
    Route::put('sites/{site}/deploy-script', [DeployScriptController::class, 'update'])->name('sites.deploy-script.update');

    Route::get('sites/{site}/commands', [SiteCommandController::class, 'index'])->name('sites.commands');
    Route::post('sites/{site}/commands', [SiteCommandController::class, 'store'])->middleware('throttle:60,1')->name('sites.commands.store');
});
