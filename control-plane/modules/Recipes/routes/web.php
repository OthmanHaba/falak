<?php

use Illuminate\Support\Facades\Route;
use Kiln\Recipes\Http\Controllers\RecipeController;
use Kiln\Recipes\Http\Controllers\RunController;

// Server page tab (/servers/{id}/recipes) owned by Recipes.
Route::middleware(['auth', 'org'])->get('servers/{server}/recipes', [RunController::class, 'server'])->name('recipes.server');

Route::middleware(['auth', 'org'])->prefix('recipes')->name('recipes.')->group(function () {
    Route::get('/', [RecipeController::class, 'index'])->name('index');
    Route::post('/', [RecipeController::class, 'store'])->name('store');

    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::get('runs/{run}', [RunController::class, 'show'])->whereUlid('run')->name('runs.show');
    Route::get('runs/{run}/status', [RunController::class, 'status'])->whereUlid('run')->name('runs.status');

    Route::post('builtin/{key}/copy', [RecipeController::class, 'copy'])->where('key', '[a-z0-9-]+')->name('builtin.copy');
    Route::get('builtin/{key}/run', [RunController::class, 'createBuiltin'])->where('key', '[a-z0-9-]+')->name('builtin.run');
    Route::post('builtin/{key}/runs', [RunController::class, 'storeBuiltin'])->where('key', '[a-z0-9-]+')->name('builtin.runs.store');

    Route::put('{recipe}', [RecipeController::class, 'update'])->whereUlid('recipe')->name('update');
    Route::delete('{recipe}', [RecipeController::class, 'destroy'])->whereUlid('recipe')->name('destroy');
    Route::get('{recipe}/run', [RunController::class, 'create'])->whereUlid('recipe')->name('run');
    Route::post('{recipe}/runs', [RunController::class, 'store'])->whereUlid('recipe')->name('runs.store');
});
