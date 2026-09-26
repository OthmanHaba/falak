<?php

use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Sites\Contracts\SiteDirectory;

require_once __DIR__.'/../Support/helpers.php';

/**
 * Deploys through every phase and returns the (single) health check request with its client options.
 *
 * @return array{url: string, options: array<string, mixed>}
 */
function health_request(DeployWorld $world): array
{
    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    $requests = $GLOBALS['deploy_http_requests'];
    expect($requests)->toHaveCount(1);

    return $requests[0];
}

it('verifies ACME certificates on the primary domain, resolved to the server', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];

    $r = health_request($world);

    expect($r['url'])->toStartWith('https://shop.example.com/')
        ->and($r['options']['verify'])->toBeTrue()
        ->and($r['options']['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:443:203.0.113.1']);
});

it('does not verify internal-CA or uploaded certificates', function (TlsMode $mode) {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.internal'];
    $world->edge->domainTls['shop.internal'] = $mode;

    expect(health_request($world)['options']['verify'])->toBeFalse();
})->with([TlsMode::Internal, TlsMode::Custom]);

it('uses plain HTTP on port 80 when TLS is off', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['intranet.lan'];
    $world->edge->domainTls['intranet.lan'] = TlsMode::Off;

    $r = health_request($world);

    expect($r['url'])->toStartWith('http://intranet.lan/')
        ->and($r['options']['curl'][CURLOPT_RESOLVE])->toBe(['intranet.lan:80:203.0.113.1']);
});

it('follows the configured TLS of hosted test domains', function () {
    $world = deploy_world(site: ['test_domain_enabled' => true]);
    $world->edge->testDomainTlsMode = TlsMode::Internal;
    config(['sites.test_domain' => 'sites.kiln.test']);

    $r = health_request($world);

    expect($r['url'])->toContain('.sites.kiln.test/')->and($r['options']['verify'])->toBeFalse();
});
