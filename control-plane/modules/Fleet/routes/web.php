<?php

use Illuminate\Support\Facades\Route;
use Falak\Fleet\Http\Controllers\CommandController;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('fleet/commands/{command}', [CommandController::class, 'show'])->name('fleet.commands.show');
});
