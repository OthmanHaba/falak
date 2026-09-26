<?php

use Illuminate\Support\Facades\Route;
use Kiln\Alerting\Http\Controllers\ChannelController;
use Kiln\Alerting\Http\Controllers\HistoryController;
use Kiln\Alerting\Http\Controllers\NotificationController;
use Kiln\Alerting\Http\Controllers\RuleController;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('alerting/channels', [ChannelController::class, 'index'])->name('alerting.channels.index');
    Route::post('alerting/channels', [ChannelController::class, 'store'])->name('alerting.channels.store');
    Route::put('alerting/channels/{channel}', [ChannelController::class, 'update'])->name('alerting.channels.update');
    Route::delete('alerting/channels/{channel}', [ChannelController::class, 'destroy'])->name('alerting.channels.destroy');
    Route::post('alerting/channels/{channel}/test', [ChannelController::class, 'test'])->middleware('throttle:10,1')->name('alerting.channels.test');

    Route::get('alerting/rules', [RuleController::class, 'index'])->name('alerting.rules.index');
    Route::post('alerting/rules', [RuleController::class, 'store'])->name('alerting.rules.store');
    Route::put('alerting/rules/{rule}', [RuleController::class, 'update'])->name('alerting.rules.update');
    Route::delete('alerting/rules/{rule}', [RuleController::class, 'destroy'])->name('alerting.rules.destroy');

    Route::get('alerting/history', HistoryController::class)->name('alerting.history');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUlid('notification')->name('notifications.read');
});
