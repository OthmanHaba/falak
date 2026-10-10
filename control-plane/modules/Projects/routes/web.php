<?php

use Falak\Projects\Http\Controllers\CanvasController;
use Falak\Projects\Http\Controllers\EnvironmentController;
use Falak\Projects\Http\Controllers\GroupController;
use Falak\Projects\Http\Controllers\ProjectController;
use Falak\Projects\Http\Controllers\ServiceController;
use Illuminate\Support\Facades\Route;

$ulid = '[0-9A-Za-z]{26}';
// Environment slug (or id) in URLs. Other modules' project pages (/projects/{project}/previews, …/volumes) are reserved
// slugs, never environments.
$patterns = ['project' => $ulid, 'environment' => '(?!(?:previews|volumes)(?:/|$))[A-Za-z0-9][A-Za-z0-9-]{0,63}', 'service' => $ulid];

Route::middleware(['auth', 'org'])->prefix('projects')->name('projects.')->group(function () use ($ulid, $patterns) {
    Route::get('/', [ProjectController::class, 'index'])->name('index');
    Route::post('/', [ProjectController::class, 'store'])->name('store');

    Route::get('{project}', [ProjectController::class, 'show'])->where($patterns)->name('show');
    Route::patch('{project}', [ProjectController::class, 'update'])->where($patterns)->name('update');
    Route::delete('{project}', [ProjectController::class, 'destroy'])->where($patterns)->name('destroy');
    Route::get('{project}/settings', [ProjectController::class, 'settings'])->where($patterns)->name('settings');
    Route::match(['put', 'delete'], '{project}/favorite', [ProjectController::class, 'favorite'])->where($patterns)->name('favorite');

    Route::post('{project}/environments', [EnvironmentController::class, 'store'])->where($patterns)->name('environments.store');
    Route::patch('{project}/environments/{environment}', [EnvironmentController::class, 'update'])->where($patterns)->name('environments.update');
    Route::delete('{project}/environments/{environment}', [EnvironmentController::class, 'destroy'])->where($patterns)->name('environments.destroy');

    Route::get('{project}/{environment}', [CanvasController::class, 'show'])->where($patterns)->name('canvas');
    Route::get('{project}/{environment}/canvas', [CanvasController::class, 'canvas'])->where($patterns)->name('canvas.data');
    Route::get('{project}/{environment}/variables', [CanvasController::class, 'variables'])->where($patterns)->name('canvas.variables');
    Route::get('{project}/{environment}/activity', [CanvasController::class, 'activity'])->where($patterns)->name('canvas.activity');
    // {item}: a record inside the tab, e.g. the Deploy view of one deployment (…/deployments/{deployment}).
    Route::get('{project}/{environment}/service/{kind}/{id}/{tab?}/{item?}', [CanvasController::class, 'panel'])
        ->where([...$patterns, 'kind' => 'site|database', 'id' => $ulid, 'tab' => '[a-z0-9-]+', 'item' => '[A-Za-z0-9-]{1,64}'])
        ->name('canvas.service');

    Route::post('{project}/{environment}/services', [ServiceController::class, 'store'])->where($patterns)->name('services.store');
    Route::patch('{project}/{environment}/services/{service}', [ServiceController::class, 'update'])->where($patterns)->name('services.update');
    Route::delete('{project}/{environment}/services/{service}', [ServiceController::class, 'destroy'])->where($patterns)->name('services.destroy');
    Route::patch('{project}/{environment}/services/{service}/position', [ServiceController::class, 'position'])->where($patterns)->name('services.position');
    Route::patch('{project}/{environment}/services/{service}/layout', [ServiceController::class, 'layout'])->where($patterns)->name('services.layout');

    // Canvas groups (layout only).
    Route::post('{project}/{environment}/groups', [GroupController::class, 'store'])->where($patterns)->name('groups.store');
    Route::patch('{project}/{environment}/groups/{group}', [GroupController::class, 'update'])->where([...$patterns, 'group' => $ulid])->name('groups.update');
    Route::delete('{project}/{environment}/groups/{group}', [GroupController::class, 'destroy'])->where([...$patterns, 'group' => $ulid])->name('groups.destroy');
});
