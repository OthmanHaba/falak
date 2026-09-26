<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Framework glue only: module routes live in modules/<Module>/routes and are loaded by each module provider.

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});
