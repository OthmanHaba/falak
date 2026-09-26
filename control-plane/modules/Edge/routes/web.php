<?php

use Illuminate\Support\Facades\Route;
use Kiln\Edge\Http\Controllers\CertificateController;
use Kiln\Edge\Http\Controllers\DnsCredentialController;
use Kiln\Edge\Http\Controllers\DomainController;
use Kiln\Edge\Http\Controllers\LoadBalancerController;
use Kiln\Edge\Http\Controllers\RoutingController;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('sites/{site}/domains', [DomainController::class, 'index'])->name('edge.domains.index');
    Route::post('sites/{site}/domains', [DomainController::class, 'store'])->name('edge.domains.store');
    Route::patch('sites/{site}/domains/{domain}', [DomainController::class, 'update'])->name('edge.domains.update');
    Route::put('sites/{site}/domains/{domain}/primary', [DomainController::class, 'primary'])->name('edge.domains.primary');
    Route::delete('sites/{site}/domains/{domain}', [DomainController::class, 'destroy'])->name('edge.domains.destroy');
    Route::post('sites/{site}/edge/apply', [DomainController::class, 'apply'])->name('edge.apply');

    Route::post('sites/{site}/certificates', [CertificateController::class, 'store'])->name('edge.certificates.store');
    Route::delete('sites/{site}/certificates/{certificate}', [CertificateController::class, 'destroy'])->name('edge.certificates.destroy');

    Route::put('sites/{site}/load-balancer', [LoadBalancerController::class, 'update'])->name('edge.load-balancer.update');
    Route::delete('sites/{site}/load-balancer', [LoadBalancerController::class, 'destroy'])->name('edge.load-balancer.destroy');

    Route::get('sites/{site}/routing', [RoutingController::class, 'index'])->name('edge.routing.index');
    Route::post('sites/{site}/redirects', [RoutingController::class, 'storeRedirect'])->name('edge.redirects.store');
    Route::delete('sites/{site}/redirects/{redirect}', [RoutingController::class, 'destroyRedirect'])->name('edge.redirects.destroy');
    Route::post('sites/{site}/security-rules', [RoutingController::class, 'storeRule'])->name('edge.security-rules.store');
    Route::delete('sites/{site}/security-rules/{rule}', [RoutingController::class, 'destroyRule'])->name('edge.security-rules.destroy');
    Route::post('sites/{site}/headers', [RoutingController::class, 'storeHeader'])->name('edge.headers.store');
    Route::delete('sites/{site}/headers/{header}', [RoutingController::class, 'destroyHeader'])->name('edge.headers.destroy');
    Route::put('sites/{site}/edge-settings', [RoutingController::class, 'updateSettings'])->name('edge.settings.update');

    Route::post('edge/dns-credentials', [DnsCredentialController::class, 'store'])->name('edge.dns-credentials.store');
    Route::delete('edge/dns-credentials/{credential}', [DnsCredentialController::class, 'destroy'])->name('edge.dns-credentials.destroy');
});
