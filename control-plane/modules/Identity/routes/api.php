<?php

use Illuminate\Support\Facades\Route;
use Kiln\Identity\Http\Controllers\Api\MeController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->group(function () {
    Route::get('me', MeController::class)->name('api.v1.me');
});
