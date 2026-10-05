<?php

use Falak\Identity\Http\Controllers\Api\MeController;
use Falak\Identity\Http\Controllers\Api\OrganizationsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'org'])->prefix('v1')->group(function () {
    Route::get('me', MeController::class)->name('api.v1.me');
    Route::get('organizations', OrganizationsController::class)->name('api.v1.organizations');
});
