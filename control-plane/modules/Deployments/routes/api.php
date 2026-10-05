<?php

use Illuminate\Support\Facades\Route;
use Falak\Deployments\Http\Controllers\Api\DeploymentApiController;
use Falak\Deployments\Http\Controllers\DeployHookController;

// Mounted under /api.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('sites/{site}/deployments', [DeploymentApiController::class, 'index'])->name('deployments.index');
    Route::post('sites/{site}/deployments', [DeploymentApiController::class, 'store'])->middleware('throttle:30,1')->name('deployments.store');
    Route::post('sites/{site}/rollback', [DeploymentApiController::class, 'rollback'])->middleware('throttle:30,1')->name('deployments.rollback');
    Route::get('sites/{site}/releases', [DeploymentApiController::class, 'releases'])->name('deployments.releases');
    Route::get('deployments/{deployment}', [DeploymentApiController::class, 'show'])->name('deployments.show');
    Route::get('deployments/{deployment}/output', [DeploymentApiController::class, 'output'])->name('deployments.output');
    Route::post('deployments/{deployment}/cancel', [DeploymentApiController::class, 'cancel'])->middleware('throttle:30,1')->name('deployments.cancel');
});

Route::match(['get', 'post'], 'deploy/{token}', DeployHookController::class)->middleware('throttle:30,1')->name('deployments.hook');
