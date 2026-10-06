<?php

use Falak\Secrets\Http\Controllers\Api\SecretApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    $secret = ['secret' => '[0-9A-Za-z]{26}'];

    Route::get('secrets', [SecretApiController::class, 'index'])->name('secrets.index');
    Route::post('secrets', [SecretApiController::class, 'store'])->name('secrets.store');
    Route::get('secrets/{secret}', [SecretApiController::class, 'show'])->where($secret)->name('secrets.show');
    Route::put('secrets/{secret}/value', [SecretApiController::class, 'setValue'])->where($secret)->name('secrets.value');
    Route::post('secrets/{secret}/rollback', [SecretApiController::class, 'rollback'])->where($secret)->name('secrets.rollback');
    Route::delete('secrets/{secret}', [SecretApiController::class, 'destroy'])->where($secret)->name('secrets.destroy');
    Route::post('secrets/{secret}/reveal', [SecretApiController::class, 'reveal'])->where($secret)->middleware('throttle:30,1')->name('secrets.reveal');
});
