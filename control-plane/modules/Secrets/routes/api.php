<?php

use Falak\Secrets\Http\Controllers\Api\ProviderApiController;
use Falak\Secrets\Http\Controllers\Api\SecretApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    $secret = ['secret' => '[0-9A-Za-z]{26}'];
    $provider = ['provider' => '[0-9A-Za-z]{26}'];

    Route::get('secrets/providers', [ProviderApiController::class, 'index'])->name('secrets.providers.index');
    Route::post('secrets/providers', [ProviderApiController::class, 'store'])->name('secrets.providers.store');
    Route::get('secrets/providers/{provider}', [ProviderApiController::class, 'show'])->where($provider)->name('secrets.providers.show');
    Route::patch('secrets/providers/{provider}', [ProviderApiController::class, 'update'])->where($provider)->name('secrets.providers.update');
    Route::delete('secrets/providers/{provider}', [ProviderApiController::class, 'destroy'])->where($provider)->name('secrets.providers.destroy');
    Route::post('secrets/providers/{provider}/test', [ProviderApiController::class, 'test'])->where($provider)->middleware('throttle:20,1')->name('secrets.providers.test');

    Route::get('secrets', [SecretApiController::class, 'index'])->name('secrets.index');
    Route::post('secrets', [SecretApiController::class, 'store'])->name('secrets.store');
    Route::get('secrets/{secret}', [SecretApiController::class, 'show'])->where($secret)->name('secrets.show');
    Route::put('secrets/{secret}/value', [SecretApiController::class, 'setValue'])->where($secret)->name('secrets.value');
    Route::post('secrets/{secret}/rollback', [SecretApiController::class, 'rollback'])->where($secret)->name('secrets.rollback');
    Route::delete('secrets/{secret}', [SecretApiController::class, 'destroy'])->where($secret)->name('secrets.destroy');
    Route::post('secrets/{secret}/reveal', [SecretApiController::class, 'reveal'])->where($secret)->middleware('throttle:30,1')->name('secrets.reveal');
});
