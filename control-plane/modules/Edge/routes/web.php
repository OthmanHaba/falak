<?php

use Illuminate\Support\Facades\Route;
use Kiln\Edge\Http\Controllers\CertificateController;
use Kiln\Edge\Http\Controllers\CloudflareController;
use Kiln\Edge\Http\Controllers\DnsController;
use Kiln\Edge\Http\Controllers\DnsCredentialController;
use Kiln\Edge\Http\Controllers\DomainController;
use Kiln\Edge\Http\Controllers\DomainSettingsController;
use Kiln\Edge\Http\Controllers\LoadBalancerController;
use Kiln\Edge\Http\Controllers\RoutingController;

Route::middleware(['auth', 'org'])->group(function () {
    Route::get('sites/{site}/domains', [DomainController::class, 'index'])->name('edge.domains.index');
    Route::post('sites/{site}/domains', [DomainController::class, 'store'])->name('edge.domains.store');
    Route::patch('sites/{site}/domains/{domain}', [DomainController::class, 'update'])->name('edge.domains.update');
    Route::put('sites/{site}/domains/{domain}/primary', [DomainController::class, 'primary'])->name('edge.domains.primary');
    Route::put('sites/{site}/domains/{domain}/cloudflare', [DomainController::class, 'cloudflare'])->name('edge.domains.cloudflare');
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

    // Domain picker (create forms, Networking) and Settings → Domains.
    Route::get('domains/options', [DnsController::class, 'options'])->name('edge.domains.options');
    Route::get('dns/check', [DnsController::class, 'check'])->middleware('throttle:60,1')->name('edge.dns.check');
    Route::get('settings/domains', [DomainSettingsController::class, 'show'])->name('edge.domain-settings');
    Route::put('settings/domains', [DomainSettingsController::class, 'update'])->name('edge.domain-settings.update');

    Route::get('settings/cloudflare', [CloudflareController::class, 'show'])->name('edge.cloudflare');
    Route::post('settings/cloudflare', [CloudflareController::class, 'connect'])->middleware('throttle:20,1')->name('edge.cloudflare.connect');
    Route::delete('settings/cloudflare/{credential}', [CloudflareController::class, 'disconnect'])->name('edge.cloudflare.disconnect');
    Route::post('settings/cloudflare/{credential}/zones', [CloudflareController::class, 'enable'])->name('edge.cloudflare.zones.enable');
    Route::patch('settings/cloudflare/zones/{zone}', [CloudflareController::class, 'update'])->name('edge.cloudflare.zones.update');
    Route::delete('settings/cloudflare/zones/{zone}', [CloudflareController::class, 'disable'])->name('edge.cloudflare.zones.disable');
    Route::put('settings/cloudflare/zones/{zone}/setting', [CloudflareController::class, 'setting'])->name('edge.cloudflare.zones.setting');
    Route::post('settings/cloudflare/zones/{zone}/sync', [CloudflareController::class, 'sync'])->name('edge.cloudflare.zones.sync');
    Route::post('settings/cloudflare/tunnels', [CloudflareController::class, 'enableTunnel'])->middleware('throttle:20,1')->name('edge.cloudflare.tunnels.enable');
    Route::delete('settings/cloudflare/tunnels/{tunnel}', [CloudflareController::class, 'disableTunnel'])->name('edge.cloudflare.tunnels.disable');
    Route::post('settings/cloudflare/tunnels/{tunnel}/reinstall', [CloudflareController::class, 'reinstallTunnel'])->name('edge.cloudflare.tunnels.reinstall');

    Route::post('edge/dns-credentials', [DnsCredentialController::class, 'store'])->name('edge.dns-credentials.store');
    Route::delete('edge/dns-credentials/{credential}', [DnsCredentialController::class, 'destroy'])->name('edge.dns-credentials.destroy');
});
