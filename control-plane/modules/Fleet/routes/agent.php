<?php

use Illuminate\Support\Facades\Route;
use Falak\Fleet\Http\Controllers\Agent\CommandEventsController;
use Falak\Fleet\Http\Controllers\Agent\CommandPollController;
use Falak\Fleet\Http\Controllers\Agent\EnrollController;
use Falak\Fleet\Http\Controllers\Agent\HeartbeatController;
use Falak\Fleet\Http\Controllers\Agent\InsightsController;
use Falak\Fleet\Http\Controllers\Agent\PingController;
use Falak\Fleet\Http\Controllers\Agent\RenewController;
use Falak\Fleet\Http\Middleware\AuthenticateAgent;
use Falak\Fleet\Http\Middleware\ForceJson;

// Mounted at /agent/v1 by the Kernel. Contract: contracts/agent-protocol/README.md.

Route::middleware(ForceJson::class)->name('fleet.agent.')->group(function () {
    Route::post('enroll', EnrollController::class)->middleware('throttle:fleet-enroll')->name('enroll');

    Route::middleware(AuthenticateAgent::class)->group(function () {
        Route::get('ping', PingController::class)->name('ping');
        Route::post('renew', RenewController::class)->name('renew');
        Route::post('heartbeat', HeartbeatController::class)->name('heartbeat');
        Route::get('commands', CommandPollController::class)->name('commands');
        Route::post('commands/{command}/events', CommandEventsController::class)->name('commands.events');
        Route::post('insights', InsightsController::class)->name('insights');
    });
});
