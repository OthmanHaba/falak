<?php

use Illuminate\Support\Facades\Route;
use Kiln\Insights\Http\Controllers\CommentController;
use Kiln\Insights\Http\Controllers\HeartbeatController;
use Kiln\Insights\Http\Controllers\IssueController;
use Kiln\Insights\Http\Controllers\OverviewController;
use Kiln\Insights\Http\Controllers\ThresholdController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(['auth', 'org'])->prefix('insights')->name('insights.')->group(function () use ($ulid) {
    Route::get('/', [OverviewController::class, 'index'])->name('index');
    Route::get('sites/{siteId}', [OverviewController::class, 'show'])->where('siteId', $ulid)->name('sites.show');

    Route::get('sites/{siteId}/settings', [ThresholdController::class, 'index'])->where('siteId', $ulid)->name('sites.settings');
    Route::post('sites/{siteId}/thresholds', [ThresholdController::class, 'store'])->where('siteId', $ulid)->name('thresholds.store');
    Route::put('thresholds/{threshold}', [ThresholdController::class, 'update'])->name('thresholds.update');
    Route::delete('thresholds/{threshold}', [ThresholdController::class, 'destroy'])->name('thresholds.destroy');

    Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
    Route::get('issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
    Route::put('issues/{issue}/status', [IssueController::class, 'status'])->name('issues.status');
    Route::put('issues/{issue}/assignee', [IssueController::class, 'assign'])->name('issues.assign');
    Route::put('issues/{issue}/priority', [IssueController::class, 'priority'])->name('issues.priority');
    Route::post('issues/{issue}/comments', [CommentController::class, 'store'])->name('issues.comments.store');
    Route::delete('issues/{issue}/comments/{comment}', [CommentController::class, 'destroy'])->name('issues.comments.destroy');

    Route::get('heartbeats', [HeartbeatController::class, 'index'])->name('heartbeats.index');
    Route::put('heartbeats/{monitor}', [HeartbeatController::class, 'update'])->name('heartbeats.update');
    Route::delete('heartbeats/{monitor}', [HeartbeatController::class, 'destroy'])->name('heartbeats.destroy');
});
