<?php

use Falak\Fleet\Http\Controllers\CommandController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('fleet/commands/{command}', [CommandController::class, 'show'])->name('fleet.commands.show');
});
