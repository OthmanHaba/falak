<?php

use Kiln\Deployments\Application\Actions\TriggerDeployment;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\SiteSettings;
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

/**
 * Deploys through every phase; returns every health check request and the deployment's final status.
 *
 * @return array{0: list<array{url: string, options: array<string, mixed>}>, 1: string}
 */
function health_requests(DeployWorld $world): array
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return [$GLOBALS['deploy_http_requests'], $deployment->refresh()->status->value];
}

it('falls back to the next domain when the primary gets no HTTP answer on the server (per-server DNS)', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['api.18-194-183-232.sslip.io', 'api.63-182-218-247.sslip.io'];
    $GLOBALS['deploy_http'] = ['https://api.18-194-183-232.sslip.io/' => 0];

    [$requests, $status] = health_requests($world);

    expect(array_column($requests, 'url'))->toBe(['https://api.18-194-183-232.sslip.io/up', 'https://api.63-182-218-247.sslip.io/up'])
        ->and($requests[1]['options']['curl'][CURLOPT_RESOLVE])->toBe(['api.63-182-218-247.sslip.io:443:203.0.113.1'])
        ->and($status)->toBe('succeeded');
});

it('never skips over an application error on the primary domain', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com', 'www.shop.example.com'];
    $GLOBALS['deploy_http'] = ['https://shop.example.com/' => 500];
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['health_retries' => 1])->save();

    [$requests, $status] = health_requests($world);

    expect(array_column($requests, 'url'))->toBe(['https://shop.example.com/up'])->and($status)->toBe('failed');
});

it('checks load-balancer backends over plain HTTP when HTTPS gets no answer', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['https://shop.example.com/' => 0];

    [$requests, $status] = health_requests($world);

    expect(array_column($requests, 'url'))->toBe(['https://shop.example.com/up', 'http://shop.example.com/up'])
        ->and($requests[1]['options']['curl'][CURLOPT_RESOLVE])->toBe(['shop.example.com:80:203.0.113.1'])
        ->and($status)->toBe('succeeded');
});

it('fails with every attempt in the message when nothing answers', function () {
    $world = deploy_world();
    $world->edge->domains[$world->site->id] = ['shop.example.com'];
    $GLOBALS['deploy_http'] = ['https://shop.example.com/' => 0, 'http://shop.example.com/' => 0];
    SiteSettings::for(app(SiteDirectory::class)->find($world->site->id))->forceFill(['health_retries' => 1])->save();

    [, $status] = health_requests($world);

    $deployment = Deployment::query()->latest('id')->firstOrFail();
    expect($status)->toBe('failed')
        ->and($deployment->error)->toContain('https://shop.example.com/')->toContain('http://shop.example.com/');
});
