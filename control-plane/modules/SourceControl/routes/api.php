<?php

use Illuminate\Support\Facades\Route;
use Falak\SourceControl\Http\Controllers\Api\ConnectionApiController;
use Falak\SourceControl\Http\Controllers\GitHubAppWebhookController;
use Falak\SourceControl\Http\Controllers\WebhookController;

// Public endpoints (authenticated by per-webhook signatures / tokens, not sessions).
Route::post('webhooks/source-control/github-app/{app}', GitHubAppWebhookController::class)
    ->middleware('throttle:source-control-github-app-webhooks')
    ->name('source-control.github-app.webhook');

Route::post('webhooks/source-control/{webhook}', WebhookController::class)
    ->middleware('throttle:source-control-webhooks')
    ->name('source-control.webhooks.receive');

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('source-control/connections', [ConnectionApiController::class, 'index'])->name('source-control.connections.index');
    Route::post('source-control/connections', [ConnectionApiController::class, 'store'])->name('source-control.connections.store');
});
