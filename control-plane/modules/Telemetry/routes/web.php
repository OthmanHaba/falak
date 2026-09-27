<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Kiln\Telemetry\Http\Controllers\LogController;
use Kiln\Telemetry\Http\Controllers\ServerMetricsController;
use Kiln\Telemetry\Http\Controllers\SettingsController;
use Kiln\Telemetry\Http\Controllers\SiteTelemetryController;
use Kiln\Telemetry\Http\Controllers\TraceController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

// /observability Traces and Logs tabs (docs/UI_DESIGN.md §3).
Route::middleware(['auth', 'org'])->prefix('observability')->name('observability.')->group(function () {
    Route::get('logs', [LogController::class, 'index'])->name('logs');
    Route::get('traces', [TraceController::class, 'index'])->name('traces.index');
    Route::get('traces/{traceId}', [TraceController::class, 'show'])->name('traces.show');
});

Route::middleware(['auth', 'org'])->prefix('telemetry')->name('telemetry.')->group(function () use ($ulid) {
    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('grafana/provision', [SettingsController::class, 'provisionGrafana'])->name('grafana.provision');

    Route::get('servers/{serverId}/metrics', [ServerMetricsController::class, 'show'])->name('servers.metrics');
    Route::get('servers/{serverId}/metrics/data', [ServerMetricsController::class, 'data'])->name('servers.metrics.data');

    // Service panel Metrics / Logs tabs (JSON).
    Route::get('sites/{siteId}', [SiteTelemetryController::class, 'context'])->where('siteId', $ulid)->name('sites.context');
    Route::get('sites/{siteId}/metrics/data', [SiteTelemetryController::class, 'metrics'])->where('siteId', $ulid)->name('sites.metrics.data');

    Route::get('logs/data', [LogController::class, 'data'])->name('logs.data');
    Route::get('traces/search', [TraceController::class, 'search'])->name('traces.search');
    Route::get('traces/{traceId}/data', [TraceController::class, 'data'])->name('traces.data');

    // Legacy page URLs → /observability.
    $keepQuery = fn (string $to) => fn (Request $request) => redirect($to.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
    Route::get('logs', $keepQuery('/observability/logs'))->name('legacy.logs');
    Route::get('traces', $keepQuery('/observability/traces'))->name('legacy.traces');
    Route::get('traces/{traceId}', fn (string $traceId) => redirect('/observability/traces/'.rawurlencode($traceId)))->where('traceId', '[0-9a-fA-F]{16,32}')->name('legacy.trace');
});
