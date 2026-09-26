<?php

use Illuminate\Support\Facades\Route;
use Kiln\Network\Http\Controllers\FirewallController;
use Kiln\Network\Http\Controllers\NetworkController;
use Kiln\Network\Http\Controllers\PrivateNetworkController;

Route::middleware(['auth', 'org'])->prefix('network')->name('network.')->group(function () {
    Route::get('/', [NetworkController::class, 'index'])->name('index');

    Route::get('servers/{server}/firewall', [FirewallController::class, 'show'])->name('firewall.show');
    Route::post('servers/{server}/firewall/rules', [FirewallController::class, 'store'])->name('firewall.rules.store');
    Route::put('servers/{server}/firewall/rules/{rule}', [FirewallController::class, 'update'])->name('firewall.rules.update');
    Route::delete('servers/{server}/firewall/rules/{rule}', [FirewallController::class, 'destroy'])->name('firewall.rules.destroy');
    Route::post('servers/{server}/firewall/apply', [FirewallController::class, 'apply'])->name('firewall.apply');

    Route::post('private-networks', [PrivateNetworkController::class, 'store'])->name('private-networks.store');
    Route::get('private-networks/{network}', [PrivateNetworkController::class, 'show'])->name('private-networks.show');
    Route::delete('private-networks/{network}', [PrivateNetworkController::class, 'destroy'])->name('private-networks.destroy');
    Route::post('private-networks/{network}/apply', [PrivateNetworkController::class, 'apply'])->name('private-networks.apply');
    Route::post('private-networks/{network}/members', [PrivateNetworkController::class, 'addMember'])->name('private-networks.members.store');
    Route::delete('private-networks/{network}/members/{member}', [PrivateNetworkController::class, 'removeMember'])->name('private-networks.members.destroy');
});
