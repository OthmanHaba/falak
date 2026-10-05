<?php

use Illuminate\Support\Facades\Route;
use Falak\Kernel\Http\LegacyRedirect;
use Falak\Telemetry\Http\Controllers\LogController;
use Falak\Telemetry\Http\Controllers\ServerMetricsController;
use Falak\Telemetry\Http\Controllers\SettingsController;
use Falak\Telemetry\Http\Controllers\SiteTelemetryController;
use Falak\Telemetry\Http\Controllers\TraceController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

// /observability Traces and Logs tabs (docs/UI_DESIGN.md §3).
Route::middleware(['auth', 'org'])->prefix('observability')->name('observability.')->group(function () {
    Route::get('logs', [LogController::class, 'index'])->name('logs');
    Route::get('traces', [TraceController::class, 'index'])->name('traces.index');
    Route::get('traces/{traceId}', [TraceController::class, 'show'])->name('traces.show');
});

Route::middleware(['auth', 'org'])->group(function () {
    // Observability settings live in the settings shell (docs/UI_DESIGN.md §3); the old URL redirects.
    Route::get('settings/observability', [SettingsController::class, 'show'])->name('telemetry.settings.show');
    Route::get('telemetry/settings', LegacyRedirect::to('/settings/observability'))->name('telemetry.settings.legacy');
});

Route::middleware(['auth', 'org'])->prefix('telemetry')->name('telemetry.')->group(function () use ($ulid) {
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('grafana/provision', [SettingsController::class, 'provisionGrafana'])->name('grafana.provision');

    Route::get('servers/{serverId}/metrics', [ServerMetricsController::class, 'show'])->name('servers.metrics');
    Route::get('servers/{serverId}/metrics/data', [ServerMetricsController::class, 'data'])->name('servers.metrics.data');

    // Service panel Metrics / Logs tabs (JSON).
    Route::get('sites/{siteId}', [SiteTelemetryController::class, 'context'])->where('siteId', $ulid)->name('sites.context');
    Route::get('sites/{siteId}/metrics/data', [SiteTelemetryController::class, 'metrics'])->where('siteId', $ulid)->name('sites.metrics.data');
    Route::get('sites/{siteId}/access-logs/data', [SiteTelemetryController::class, 'accessLogs'])->where('siteId', $ulid)->name('sites.access-logs.data');

    Route::get('logs/data', [LogController::class, 'data'])->name('logs.data');
    Route::get('traces/search', [TraceController::class, 'search'])->name('traces.search');
    Route::get('traces/{traceId}/data', [TraceController::class, 'data'])->name('traces.data');

    // Legacy page URLs → /observability.
    Route::get('logs', LegacyRedirect::to('/observability/logs'))->name('legacy.logs');
    Route::get('traces', LegacyRedirect::to('/observability/traces'))->name('legacy.traces');
    Route::get('traces/{traceId}', fn (string $traceId) => redirect('/observability/traces/'.rawurlencode($traceId), 301))->where('traceId', '[0-9a-fA-F]{16,32}')->name('legacy.trace');
});
