<?php

use Illuminate\Support\Facades\Route;
use Kiln\Providers\Http\Controllers\CatalogController;
use Kiln\Providers\Http\Controllers\ProviderCredentialController;

Route::middleware(['auth', 'org', 'org.can:providers.view'])->prefix('providers')->name('providers.')->group(function () {
    Route::get('/', [ProviderCredentialController::class, 'index'])->name('index');
    Route::post('/', [ProviderCredentialController::class, 'store'])->middleware('org.can:providers.manage')->name('store');
    Route::patch('{credential}', [ProviderCredentialController::class, 'update'])->name('update');
    Route::post('{credential}/verify', [ProviderCredentialController::class, 'verify'])->name('verify');
    Route::delete('{credential}', [ProviderCredentialController::class, 'destroy'])->name('destroy');

    Route::get('{credential}/regions', [CatalogController::class, 'regions'])->name('regions');
    Route::get('{credential}/sizes', [CatalogController::class, 'sizes'])->name('sizes');
    Route::get('{credential}/images', [CatalogController::class, 'images'])->name('images');
});
