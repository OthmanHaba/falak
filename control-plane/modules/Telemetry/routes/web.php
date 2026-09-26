<?php

use Illuminate\Support\Facades\Route;
use Kiln\Telemetry\Http\Controllers\LogController;
use Kiln\Telemetry\Http\Controllers\ServerMetricsController;
use Kiln\Telemetry\Http\Controllers\SettingsController;
use Kiln\Telemetry\Http\Controllers\TraceController;

Route::middleware(['auth', 'org'])->prefix('telemetry')->name('telemetry.')->group(function () {
    Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('grafana/provision', [SettingsController::class, 'provisionGrafana'])->name('grafana.provision');

    Route::get('servers/{serverId}/metrics', [ServerMetricsController::class, 'show'])->name('servers.metrics');
    Route::get('servers/{serverId}/metrics/data', [ServerMetricsController::class, 'data'])->name('servers.metrics.data');

    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('logs/data', [LogController::class, 'data'])->name('logs.data');

    Route::get('traces', [TraceController::class, 'index'])->name('traces.index');
    Route::get('traces/search', [TraceController::class, 'search'])->name('traces.search');
    Route::get('traces/{traceId}', [TraceController::class, 'show'])->name('traces.show');
    Route::get('traces/{traceId}/data', [TraceController::class, 'data'])->name('traces.data');
});
