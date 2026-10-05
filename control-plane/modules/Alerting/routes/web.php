<?php

use Illuminate\Support\Facades\Route;
use Falak\Alerting\Http\Controllers\ChannelController;
use Falak\Alerting\Http\Controllers\HistoryController;
use Falak\Alerting\Http\Controllers\NotificationController;
use Falak\Alerting\Http\Controllers\RuleController;
use Falak\Kernel\Http\LegacyRedirect;

Route::middleware(['auth', 'org'])->group(function () {
    // Channels and rules live in the settings shell (docs/UI_DESIGN.md §3); the old URLs redirect.
    Route::get('settings/alert-channels', [ChannelController::class, 'index'])->name('alerting.channels.index');
    Route::get('alerting/channels', LegacyRedirect::to('/settings/alert-channels'))->name('alerting.channels.legacy');
    Route::post('alerting/channels', [ChannelController::class, 'store'])->name('alerting.channels.store');
    Route::put('alerting/channels/{channel}', [ChannelController::class, 'update'])->name('alerting.channels.update');
    Route::delete('alerting/channels/{channel}', [ChannelController::class, 'destroy'])->name('alerting.channels.destroy');
    Route::post('alerting/channels/{channel}/test', [ChannelController::class, 'test'])->middleware('throttle:10,1')->name('alerting.channels.test');

    Route::get('settings/alert-rules', [RuleController::class, 'index'])->name('alerting.rules.index');
    Route::get('alerting/rules', LegacyRedirect::to('/settings/alert-rules'))->name('alerting.rules.legacy');
    Route::post('alerting/rules', [RuleController::class, 'store'])->name('alerting.rules.store');
    Route::put('alerting/rules/{rule}', [RuleController::class, 'update'])->name('alerting.rules.update');
    Route::delete('alerting/rules/{rule}', [RuleController::class, 'destroy'])->name('alerting.rules.destroy');

    // /observability Alerts tab (history); channels and rules are managed under /settings.
    Route::get('observability/alerts', HistoryController::class)->name('observability.alerts');
    Route::get('alerting/history', LegacyRedirect::to('/observability/alerts'))->name('alerting.history');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUlid('notification')->name('notifications.read');
});
