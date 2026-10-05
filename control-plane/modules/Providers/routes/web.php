<?php

use Falak\Kernel\Http\LegacyRedirect;
use Falak\Providers\Http\Controllers\CatalogController;
use Falak\Providers\Http\Controllers\ProviderCredentialController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org', 'org.can:providers.view'])->group(function () {
    // The page lives in the settings shell (docs/UI_DESIGN.md §3); the old URL redirects (keeping ?add=1).
    Route::get('settings/cloud-providers', [ProviderCredentialController::class, 'index'])->name('providers.index');
    Route::get('providers', LegacyRedirect::to('/settings/cloud-providers'))->name('providers.legacy');
});

Route::middleware(['auth', 'org', 'org.can:providers.view'])->prefix('providers')->name('providers.')->group(function () {
    Route::post('/', [ProviderCredentialController::class, 'store'])->middleware('org.can:providers.manage')->name('store');
    Route::patch('{credential}', [ProviderCredentialController::class, 'update'])->name('update');
    Route::post('{credential}/verify', [ProviderCredentialController::class, 'verify'])->name('verify');
    Route::delete('{credential}', [ProviderCredentialController::class, 'destroy'])->name('destroy');

    Route::get('{credential}/regions', [CatalogController::class, 'regions'])->name('regions');
    Route::get('{credential}/sizes', [CatalogController::class, 'sizes'])->name('sizes');
    Route::get('{credential}/images', [CatalogController::class, 'images'])->name('images');
});
