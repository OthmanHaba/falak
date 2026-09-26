<?php

use Illuminate\Support\Facades\Route;
use Kiln\Sites\Http\Controllers\Api\SiteApiController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('sites', [SiteApiController::class, 'index'])->name('sites.index');
    Route::get('sites/{site}', [SiteApiController::class, 'show'])->name('sites.show');
});
