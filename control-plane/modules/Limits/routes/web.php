<?php

use Falak\Limits\Http\Controllers\CapacityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('servers/{server}/capacity', [CapacityController::class, 'show'])->where('server', '[0-9A-Za-z]{26}')->name('limits.capacity');
});
