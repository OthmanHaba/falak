<?php

use Falak\Secrets\Http\Controllers\SecretController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    $ulid = '[0-9A-Za-z]{26}';

    // Pages: a project's secrets (under its settings), and the organization's own.
    Route::get('projects/{project}/settings/secrets', [SecretController::class, 'project'])->where('project', $ulid)->name('secrets.project');
    Route::get('settings/secrets', [SecretController::class, 'organization'])->name('secrets.organization');

    Route::prefix('secrets')->name('secrets.')->group(function () use ($ulid) {
        Route::post('/', [SecretController::class, 'store'])->name('store');
        Route::post('reauthenticate', [SecretController::class, 'reauthenticate'])->middleware('throttle:10,1')->name('reauthenticate');
        Route::get('promotable', [SecretController::class, 'promotable'])->name('promotable');
        Route::post('promote', [SecretController::class, 'promote'])->name('promote');

        Route::get('{secret}', [SecretController::class, 'show'])->where('secret', $ulid)->name('show');
        Route::patch('{secret}', [SecretController::class, 'update'])->where('secret', $ulid)->name('update');
        Route::delete('{secret}', [SecretController::class, 'destroy'])->where('secret', $ulid)->name('destroy');
        Route::post('{secret}/versions', [SecretController::class, 'setValue'])->where('secret', $ulid)->name('versions.store');
        Route::post('{secret}/versions/{version}/restore', [SecretController::class, 'restore'])->where(['secret' => $ulid, 'version' => '[0-9]+'])->name('versions.restore');
        Route::post('{secret}/versions/{version}/disable', [SecretController::class, 'disable'])->where(['secret' => $ulid, 'version' => '[0-9]+'])->name('versions.disable');
        Route::post('{secret}/reveal', [SecretController::class, 'reveal'])->where('secret', $ulid)->middleware('throttle:30,1')->name('reveal');
    });
});
