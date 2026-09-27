<?php

use Illuminate\Support\Facades\Route;
use Kiln\Insights\Http\Controllers\CommentController;
use Kiln\Insights\Http\Controllers\HeartbeatController;
use Kiln\Insights\Http\Controllers\IssueController;
use Kiln\Insights\Http\Controllers\OverviewController;
use Kiln\Insights\Http\Controllers\ThresholdController;
use Kiln\Kernel\Http\LegacyRedirect;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

// /observability (docs/UI_DESIGN.md §3): Overview · Issues · Heartbeats tabs live here; Telemetry adds Traces and
// Logs, Alerting adds Alerts.
Route::middleware(['auth', 'org'])->prefix('observability')->name('observability.')->group(function () {
    Route::get('/', [OverviewController::class, 'index'])->name('overview');
    Route::get('issues', [IssueController::class, 'index'])->name('issues.index');
    Route::get('issues/{issue}', [IssueController::class, 'show'])->name('issues.show');
    Route::get('heartbeats', [HeartbeatController::class, 'index'])->name('heartbeats');
});

Route::middleware(['auth', 'org'])->prefix('insights')->name('insights.')->group(function () use ($ulid) {
    // Service panel Observability tab (JSON).
    Route::get('sites/{siteId}/summary', [OverviewController::class, 'summary'])->where('siteId', $ulid)->name('sites.summary');

    Route::get('sites/{siteId}/settings', [ThresholdController::class, 'index'])->where('siteId', $ulid)->name('sites.settings');
    Route::post('sites/{siteId}/thresholds', [ThresholdController::class, 'store'])->where('siteId', $ulid)->name('thresholds.store');
    Route::put('thresholds/{threshold}', [ThresholdController::class, 'update'])->name('thresholds.update');
    Route::delete('thresholds/{threshold}', [ThresholdController::class, 'destroy'])->name('thresholds.destroy');

    Route::put('issues/bulk/status', [IssueController::class, 'bulkStatus'])->name('issues.bulk-status');
    Route::put('issues/{issue}/status', [IssueController::class, 'status'])->name('issues.status');
    Route::put('issues/{issue}/assignee', [IssueController::class, 'assign'])->name('issues.assign');
    Route::put('issues/{issue}/priority', [IssueController::class, 'priority'])->name('issues.priority');
    Route::post('issues/{issue}/comments', [CommentController::class, 'store'])->name('issues.comments.store');
    Route::delete('issues/{issue}/comments/{comment}', [CommentController::class, 'destroy'])->name('issues.comments.destroy');

    Route::put('heartbeats/{monitor}', [HeartbeatController::class, 'update'])->name('heartbeats.update');
    Route::delete('heartbeats/{monitor}', [HeartbeatController::class, 'destroy'])->name('heartbeats.destroy');

    // Legacy page URLs (alert links, bookmarks, CLI `open`) → /observability.
    Route::get('/', LegacyRedirect::to('/observability'))->name('legacy.index');
    Route::get('issues', LegacyRedirect::to('/observability/issues'))->name('legacy.issues');
    Route::get('issues/{issueId}', fn (string $issueId) => redirect('/observability/issues/'.rawurlencode($issueId), 301))->where('issueId', $ulid)->name('legacy.issue');
    Route::get('heartbeats', LegacyRedirect::to('/observability/heartbeats'))->name('legacy.heartbeats');
    Route::get('sites/{siteId}', [OverviewController::class, 'show'])->name('legacy.site');
});
