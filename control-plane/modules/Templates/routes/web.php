<?php

use Falak\Templates\Http\Controllers\CustomTemplateController;
use Falak\Templates\Http\Controllers\TemplateController;
use Falak\Templates\Http\Controllers\TemplateDeployController;
use Illuminate\Support\Facades\Route;

$ulid = '[0-9A-Za-z]{26}';
$slug = '[a-z0-9][a-z0-9-]{0,49}';

Route::middleware(['auth', 'org'])->group(function () use ($ulid, $slug) {
    // Gallery + details (docs/COMPOSE_TEMPLATES.md §3).
    Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::get('templates/catalog/{slug}/icon.svg', [TemplateController::class, 'icon'])->where('slug', $slug)->name('templates.icon');
    Route::get('templates/{source}/{slug}', [TemplateController::class, 'show'])->where(['source' => 'catalog|custom', 'slug' => $slug])->name('templates.show');
    Route::get('templates/{source}/{slug}/generate/{key}', [TemplateController::class, 'generate'])
        ->where(['source' => 'catalog|custom', 'slug' => $slug, 'key' => '[A-Z_][A-Z0-9_]{0,127}'])->name('templates.generate');

    // Deploy from the canvas (Create picker → Template) or the /templates page.
    Route::post('projects/{project}/{environment}/templates/{slug}/deploy', TemplateDeployController::class)
        ->where(['project' => $ulid, 'environment' => '[A-Za-z0-9][A-Za-z0-9-]{0,63}', 'slug' => $slug])
        ->middleware('throttle:30,1')
        ->name('templates.deploy');

    // Settings → Templates (organization templates).
    Route::get('settings/templates', [CustomTemplateController::class, 'index'])->name('templates.settings');
    Route::post('settings/templates', [CustomTemplateController::class, 'store'])->name('templates.custom.store');
    Route::post('settings/templates/preview', [CustomTemplateController::class, 'preview'])->name('templates.custom.preview');
    Route::post('settings/templates/fetch', [CustomTemplateController::class, 'fetch'])->middleware('throttle:20,1')->name('templates.custom.fetch');
    Route::post('settings/templates/from-site/{site}', [CustomTemplateController::class, 'fromSite'])->where('site', $ulid)->name('templates.custom.from-site');
    Route::get('settings/templates/{template}', [CustomTemplateController::class, 'show'])->where('template', $ulid)->name('templates.custom.show');
    Route::put('settings/templates/{template}', [CustomTemplateController::class, 'update'])->where('template', $ulid)->name('templates.custom.update');
    Route::delete('settings/templates/{template}', [CustomTemplateController::class, 'destroy'])->where('template', $ulid)->name('templates.custom.destroy');
});
