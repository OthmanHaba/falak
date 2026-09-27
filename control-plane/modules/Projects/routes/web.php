<?php

use Illuminate\Support\Facades\Route;
use Kiln\Projects\Http\Controllers\CanvasController;
use Kiln\Projects\Http\Controllers\EnvironmentController;
use Kiln\Projects\Http\Controllers\ProjectController;
use Kiln\Projects\Http\Controllers\ServiceController;

$ulid = '[0-9A-Za-z]{26}';
// Environment slug (or id) in URLs.
$patterns = ['project' => $ulid, 'environment' => '[A-Za-z0-9][A-Za-z0-9-]{0,63}', 'service' => $ulid];

Route::middleware(['auth', 'org'])->prefix('projects')->name('projects.')->group(function () use ($ulid, $patterns) {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store');

    Route::get('{project}', [ProjectController::class, 'show'])->where($patterns)->name('show');
    Route::patch('{project}', [ProjectController::class, 'update'])->where($patterns)->name('update');
    Route::delete('{project}', [ProjectController::class, 'destroy'])->where($patterns)->name('destroy');
    Route::get('{project}/settings', [ProjectController::class, 'settings'])->where($patterns)->name('settings');

    Route::post('{project}/environments', [EnvironmentController::class, 'store'])->where($patterns)->name('environments.store');
    Route::patch('{project}/environments/{environment}', [EnvironmentController::class, 'update'])->where($patterns)->name('environments.update');
    Route::delete('{project}/environments/{environment}', [EnvironmentController::class, 'destroy'])->where($patterns)->name('environments.destroy');

    Route::get('{project}/{environment}', [CanvasController::class, 'show'])->where($patterns)->name('canvas');
    Route::get('{project}/{environment}/canvas', [CanvasController::class, 'canvas'])->where($patterns)->name('canvas.data');
    Route::get('{project}/{environment}/activity', [CanvasController::class, 'activity'])->where($patterns)->name('canvas.activity');
    // {item}: a record inside the tab, e.g. the Deploy view of one deployment (…/deployments/{deployment}).
    Route::get('{project}/{environment}/service/{kind}/{id}/{tab?}/{item?}', [CanvasController::class, 'panel'])
        ->where([...$patterns, 'kind' => 'site|database', 'id' => $ulid, 'tab' => '[a-z0-9-]+', 'item' => '[A-Za-z0-9-]{1,64}'])
        ->name('canvas.service');

    Route::post('{project}/{environment}/services', [ServiceController::class, 'store'])->where($patterns)->name('services.store');
    Route::patch('{project}/{environment}/services/{service}', [ServiceController::class, 'update'])->where($patterns)->name('services.update');
    Route::patch('{project}/{environment}/services/{service}/position', [ServiceController::class, 'position'])->where($patterns)->name('services.position');
});
