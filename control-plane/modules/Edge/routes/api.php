<?php

use Illuminate\Support\Facades\Route;
use Kiln\Edge\Http\Controllers\DnsController;
use Kiln\Edge\Http\Controllers\RateLimitController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('domains/options', [DnsController::class, 'options'])->name('domains.options');
    Route::get('sites/{site}/domains/{domain}/rate-limit', [RateLimitController::class, 'show'])->name('domains.rate-limit.show');
    Route::put('sites/{site}/domains/{domain}/rate-limit', [RateLimitController::class, 'update'])->middleware('throttle:30,1')->name('domains.rate-limit.update');
    Route::delete('sites/{site}/domains/{domain}/rate-limit', [RateLimitController::class, 'destroy'])->name('domains.rate-limit.destroy');
    Route::get('dns/check', [DnsController::class, 'check'])->middleware('throttle:60,1')->name('dns.check');
});
