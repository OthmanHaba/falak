<?php

use Illuminate\Support\Facades\Route;
use Kiln\Fleet\Http\Controllers\CommandController;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('fleet/commands/{command}', [CommandController::class, 'show'])->name('fleet.commands.show');
});
