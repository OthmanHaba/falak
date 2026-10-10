<?php

use Falak\Deployments\Http\Controllers\DeploymentController;
use Falak\Deployments\Http\Controllers\DeploySettingsController;
use Falak\Deployments\Http\Controllers\ReleaseController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'org'])->prefix('sites/{site}')->group(function () {
    Route::get('deployments', [DeploymentController::class, 'index'])->name('deployments.index');
    Route::post('deployments', [DeploymentController::class, 'store'])->middleware('throttle:30,1')->name('deployments.store');
    Route::get('deployments/{deployment}', [DeploymentController::class, 'show'])->name('deployments.show');
    Route::get('deployments/{deployment}/state', [DeploymentController::class, 'state'])->name('deployments.state');
    Route::post('deployments/{deployment}/cancel', [DeploymentController::class, 'cancel'])->name('deployments.cancel');

    Route::get('releases', [ReleaseController::class, 'index'])->name('deployments.releases');
    Route::post('releases/{release}/rollback', [ReleaseController::class, 'rollback'])->middleware('throttle:30,1')->name('deployments.releases.rollback');

    Route::get('deploy-settings', [DeploySettingsController::class, 'show'])->name('deployments.settings');
    Route::put('deploy-settings', [DeploySettingsController::class, 'update'])->name('deployments.settings.update');
    Route::put('deploy-settings/watch', [DeploySettingsController::class, 'updateWatch'])->name('deployments.settings.watch');
    Route::put('deploy-settings/push-to-deploy', [DeploySettingsController::class, 'pushToDeploy'])->name('deployments.settings.push');
    Route::post('deploy-settings/hook', [DeploySettingsController::class, 'rotateHook'])->name('deployments.settings.hook.rotate');
    Route::delete('deploy-settings/hook', [DeploySettingsController::class, 'disableHook'])->name('deployments.settings.hook.disable');
});
