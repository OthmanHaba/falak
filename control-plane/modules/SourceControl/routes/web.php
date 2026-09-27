<?php

use Illuminate\Support\Facades\Route;
use Kiln\Kernel\Http\LegacyRedirect;
use Kiln\SourceControl\Http\Controllers\ConnectionController;
use Kiln\SourceControl\Http\Controllers\OAuthController;

Route::middleware(['auth', 'org'])->group(function () {
    // The page lives in the settings shell (docs/UI_DESIGN.md §3); the old URL redirects.
    Route::get('settings/source-control', [ConnectionController::class, 'index'])->name('source-control.index');
    Route::get('source-control', LegacyRedirect::to('/settings/source-control'))->name('source-control.legacy');
});

Route::middleware(['auth', 'org'])->prefix('source-control')->name('source-control.')->group(function () {
    Route::post('connections', [ConnectionController::class, 'store'])->name('connections.store');
    Route::delete('connections/{connection}', [ConnectionController::class, 'destroy'])->name('connections.destroy');
    Route::get('connections/{connection}/repositories', [ConnectionController::class, 'repositories'])->name('connections.repositories');
    Route::get('connections/{connection}/branches', [ConnectionController::class, 'branches'])->name('connections.branches');

    Route::get('connect/github-app', [OAuthController::class, 'githubApp'])->name('github-app');
    Route::get('github-app/setup', [OAuthController::class, 'githubAppSetup'])->name('github-app.setup');
    Route::get('connect/{provider}', [OAuthController::class, 'redirect'])->name('connect');
    Route::get('callback/{provider}', [OAuthController::class, 'callback'])->name('callback');
});
