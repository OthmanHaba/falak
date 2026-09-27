<?php

use Illuminate\Support\Facades\Route;
use Kiln\Templates\Http\Controllers\TemplateDeployController;

// Public API (token auth): deploy a template into a project environment — same body and response as the web route.
Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->name('api.v1.')->group(function () {
    Route::post('projects/{project}/{environment}/templates/{slug}/deploy', TemplateDeployController::class)
        ->where(['project' => '[0-9A-Za-z]{26}', 'slug' => '[a-z0-9][a-z0-9-]{0,49}'])
        ->middleware('throttle:30,1')
        ->name('templates.deploy');
});
