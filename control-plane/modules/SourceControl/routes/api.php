<?php

use Illuminate\Support\Facades\Route;
use Kiln\SourceControl\Http\Controllers\WebhookController;

// Public endpoint (authenticated by per-webhook signatures / tokens, not sessions).
Route::post('webhooks/source-control/{webhook}', WebhookController::class)
    ->middleware('throttle:source-control-webhooks')
    ->name('source-control.webhooks.receive');
