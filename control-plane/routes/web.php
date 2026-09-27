<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Framework glue only: module routes live in modules/<Module>/routes and are loaded by each module provider.

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    // Home is the projects grid (docs/UI_DESIGN.md §3); the old dashboard URL keeps working.
    Route::redirect('dashboard', '/projects')->name('dashboard');

    // Kiln component gallery for UI work; local environment only.
    if (app()->environment('local')) {
        Route::get('dev/components', fn () => Inertia::render('dev/components'))->name('dev.components');
    }
});
