<?php

use Illuminate\Support\Facades\Route;
use Kiln\SourceControl\Http\Controllers\Api\ConnectionApiController;
use Kiln\SourceControl\Http\Controllers\WebhookController;

// Public endpoint (authenticated by per-webhook signatures / tokens, not sessions).
Route::post('webhooks/source-control/{webhook}', WebhookController::class)
    ->middleware('throttle:source-control-webhooks')
    ->name('source-control.webhooks.receive');

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('source-control/connections', [ConnectionApiController::class, 'index'])->name('source-control.connections.index');
    Route::post('source-control/connections', [ConnectionApiController::class, 'store'])->name('source-control.connections.store');
});
