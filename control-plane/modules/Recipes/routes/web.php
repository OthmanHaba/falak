<?php

use Illuminate\Support\Facades\Route;
use Kiln\Kernel\Http\LegacyRedirect;
use Kiln\Recipes\Http\Controllers\RecipeController;
use Kiln\Recipes\Http\Controllers\RunController;

Route::middleware(['auth', 'org'])->group(function () {
    // The recipe library lives in the settings shell (docs/UI_DESIGN.md §3); run pages stay under /recipes.
    Route::get('settings/recipes', [RecipeController::class, 'index'])->name('recipes.index');
    Route::get('recipes', LegacyRedirect::to('/settings/recipes'))->name('recipes.legacy');
});

Route::middleware(['auth', 'org'])->prefix('recipes')->name('recipes.')->group(function () {
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
