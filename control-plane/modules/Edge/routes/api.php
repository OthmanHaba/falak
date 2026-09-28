<?php

use Illuminate\Support\Facades\Route;
use Kiln\Edge\Http\Controllers\DnsController;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::get('domains/options', [DnsController::class, 'options'])->name('domains.options');
    Route::get('dns/check', [DnsController::class, 'check'])->middleware('throttle:60,1')->name('dns.check');
});
