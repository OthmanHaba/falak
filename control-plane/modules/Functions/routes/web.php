<?php

use Illuminate\Support\Facades\Route;
use Kiln\Functions\Http\Controllers\CreateFunctionController;
use Kiln\Functions\Http\Controllers\FunctionController;

$ulid = '[0-9A-Za-z]{26}';

Route::middleware(['auth', 'org'])->group(function () use ($ulid) {
    Route::get('functions/starters', [CreateFunctionController::class, 'starters'])->name('functions.starters');
    Route::post('projects/{project}/{environment}/functions', [CreateFunctionController::class, 'store'])
        ->where(['project' => $ulid, 'environment' => '[A-Za-z0-9][A-Za-z0-9-]{0,63}'])
        ->middleware('throttle:30,1')
        ->name('functions.store');

    Route::prefix('sites/{site}/function')->where(['site' => $ulid])->group(function () {
        Route::get('/', [FunctionController::class, 'show'])->name('functions.show');
        Route::get('status', [FunctionController::class, 'status'])->middleware('throttle:120,1')->name('functions.status');
        Route::put('draft', [FunctionController::class, 'saveDraft'])->middleware('throttle:120,1')->name('functions.draft.save');
        Route::delete('draft', [FunctionController::class, 'discardDraft'])->name('functions.draft.discard');
        Route::post('deploy', [FunctionController::class, 'deploy'])->middleware('throttle:30,1')->name('functions.deploy');
        Route::get('versions', [FunctionController::class, 'versions'])->name('functions.versions');
        Route::get('versions/{number}', [FunctionController::class, 'version'])->whereNumber('number')->name('functions.version');
        Route::post('versions/{number}/deploy', [FunctionController::class, 'deployVersion'])->whereNumber('number')->middleware('throttle:30,1')->name('functions.version.deploy');
        Route::put('settings', [FunctionController::class, 'updateSettings'])->name('functions.settings');
    });
});
