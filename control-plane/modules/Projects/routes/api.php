<?php

use Illuminate\Support\Facades\Route;
use Kiln\Projects\Http\Controllers\Api\ProjectApiController;
use Kiln\Projects\Http\Controllers\ServiceController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('projects', [ProjectApiController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectApiController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectApiController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectApiController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectApiController::class, 'destroy'])->name('projects.destroy');

    Route::get('projects/{project}/environments', [ProjectApiController::class, 'environments'])->name('projects.environments.index');
    Route::post('projects/{project}/environments', [ProjectApiController::class, 'storeEnvironment'])->name('projects.environments.store');
    Route::patch('projects/{project}/environments/{environment}', [ProjectApiController::class, 'updateEnvironment'])->name('projects.environments.update');
    Route::delete('projects/{project}/environments/{environment}', [ProjectApiController::class, 'destroyEnvironment'])->name('projects.environments.destroy');
    // Same as the canvas' Create: a database (PostgreSQL, MySQL, MariaDB) or a Redis / Valkey instance, or a site.
    Route::post('projects/{project}/environments/{environment}/services', [ServiceController::class, 'store'])->name('projects.services.store');
});
