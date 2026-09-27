<?php

use Illuminate\Support\Facades\Route;
use Kiln\Builds\Http\Controllers\BuildController;
use Kiln\Builds\Http\Controllers\BuilderBinaryController;
use Kiln\Builds\Http\Controllers\BuilderController;
use Kiln\Kernel\Http\LegacyRedirect;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('builds', [BuildController::class, 'index'])->name('builds.index');
    // Builders live in the settings shell (docs/UI_DESIGN.md §3); the old URL redirects (before builds/{build}).
    Route::get('settings/builders', [BuilderController::class, 'index'])->name('builds.builders.index');
    Route::get('builds/builders', LegacyRedirect::to('/settings/builders'))->name('builds.builders.legacy');
    Route::post('builds/builders', [BuilderController::class, 'store'])->name('builds.builders.store');
    Route::patch('builds/builders/{builder}', [BuilderController::class, 'update'])->name('builds.builders.update');
    Route::post('builds/builders/{builder}/reinstall', [BuilderController::class, 'reinstall'])->name('builds.builders.reinstall');
    Route::delete('builds/builders/{builder}', [BuilderController::class, 'destroy'])->name('builds.builders.destroy');
    Route::get('builds/{build}', [BuildController::class, 'show'])->name('builds.show');
    Route::get('builds/{build}/output', [BuildController::class, 'output'])->name('builds.output');
    Route::post('builds/{build}/cancel', [BuildController::class, 'cancel'])->name('builds.cancel');
});

Route::get('install/builder/linux-{arch}', BuilderBinaryController::class)->whereIn('arch', ['amd64', 'arm64'])->middleware('throttle:60,1')->name('builds.install.binary');
