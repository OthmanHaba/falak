<?php

use Illuminate\Support\Facades\Route;
use Kiln\Sites\Http\Controllers\Api\SiteApiController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('sites', [SiteApiController::class, 'index'])->name('sites.index');
    Route::post('sites', [SiteApiController::class, 'store'])->name('sites.store');
    Route::get('sites/{site}', [SiteApiController::class, 'show'])->name('sites.show');
    Route::get('sites/{site}/env', [SiteApiController::class, 'env'])->middleware('throttle:60,1')->name('sites.env');
    Route::put('sites/{site}/env', [SiteApiController::class, 'updateEnv'])->middleware('throttle:60,1')->name('sites.env.update');
    Route::put('sites/{site}/laravel', [SiteApiController::class, 'updateLaravel'])->middleware('throttle:60,1')->name('sites.laravel.update');
});
