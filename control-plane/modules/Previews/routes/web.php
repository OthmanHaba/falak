<?php

use Falak\Previews\Http\Controllers\PreviewController;
use Falak\Previews\Http\Controllers\PreviewDomainController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    $ulid = '[0-9A-Za-z]{26}';

    // A project's Previews tab ("previews" is a reserved environment slug).
    Route::get('projects/{project}/previews', [PreviewController::class, 'index'])->where('project', $ulid)->name('previews.index');
    Route::put('projects/{project}/previews/settings', [PreviewController::class, 'settings'])->where('project', $ulid)->name('previews.settings');

    Route::post('previews/{preview}/approve', [PreviewController::class, 'approve'])->where('preview', $ulid)->name('previews.approve');
    Route::post('previews/{preview}/redeploy', [PreviewController::class, 'redeploy'])->where('preview', $ulid)->middleware('throttle:20,1')->name('previews.redeploy');
    Route::delete('previews/{preview}', [PreviewController::class, 'destroy'])->where('preview', $ulid)->name('previews.destroy');

    // Settings → Previews: the instance's preview domain.
    Route::get('settings/previews', [PreviewDomainController::class, 'show'])->name('previews.domain');
    Route::put('settings/previews', [PreviewDomainController::class, 'update'])->name('previews.domain.update');
    Route::delete('settings/previews', [PreviewDomainController::class, 'destroy'])->name('previews.domain.destroy');
});
