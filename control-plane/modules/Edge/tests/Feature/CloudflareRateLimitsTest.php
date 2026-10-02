<?php

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Application\Actions\AddDomain;
use Kiln\Edge\Application\Actions\RemoveDomain;
use Kiln\Edge\Application\CloudflareConnections;
use Kiln\Edge\Application\CloudflareRateLimits;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Infrastructure\Cloudflare\CloudflareError;
use Kiln\Edge\Tests\Support\FakeCloudflare;
use Kiln\Identity\Contracts\Role;

/*
 * Rate limits through Cloudflare (Kiln's edge has none): Kiln's rules in the zone's http_ratelimit entry point,
 * merged with the zone's own, within what the plan allows. Cloudflare is faked.
 */

require_once __DIR__.'/../Support/helpers.php';

const RL_RULE = ['path' => '/login', 'requests' => 20, 'period' => 10, 'action' => 'block', 'timeout' => 10];

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers] = edge_fakes();
    $this->cf = FakeCloudflare::install();
    $this->zoneId = $this->cf->zone('example.com');
    $this->org = strtolower((string) Str::ulid());
    $web = edge_server($this->servers, $this->org, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-1', 'ipv4' => '203.0.113.10']);
    $this->site = edge_site($this->sites, $this->org, [$web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'shop']);
    $connections = app(CloudflareConnections::class);
    $credential = $connections->connect($this->org, 'Cloudflare', $this->cf->validToken, null);
    $this->zone = $connections->enable($credential, $this->zoneId, true);
    $this->limits = app(CloudflareRateLimits::class);
});

it('adds a host + path rule on paid plans and keeps the zone’s own rules', function () {
    $this->cf->plans[$this->zoneId] = 'pro';
    $this->cf->rateLimits[$this->zoneId] = [['id' => 'theirs', 'description' => 'their rule', 'expression' => '(http.request.uri.path eq "/x")', 'action' => 'block', 'ratelimit' => ['characteristics' => ['cf.colo.id', 'ip.src'], 'period' => 10, 'requests_per_period' => 5, 'mitigation_timeout' => 10], 'enabled' => true]];
    $domain = app(AddDomain::class)($this->site, 'shop.example.com', www: WwwRedirect::ToApex);

    $this->limits->set($domain, [...RL_RULE, 'period' => 60, 'timeout' => 600, 'action' => 'managed_challenge']);

    $rules = $this->cf->rateLimits[$this->zoneId];
    expect(array_column($rules, 'description'))->toBe(['their rule', "kiln:ratelimit:{$domain->id} shop.example.com"])
        ->and($rules[1]['expression'])->toBe('(http.host in {"shop.example.com" "www.shop.example.com"} and starts_with(http.request.uri.path, "/login"))')
        ->and($rules[1]['action'])->toBe('managed_challenge')
        ->and($rules[1]['ratelimit'])->toBe(['characteristics' => ['cf.colo.id', 'ip.src'], 'period' => 60, 'requests_per_period' => 20, 'mitigation_timeout' => 600])
        ->and($domain->refresh()->cloudflare_rate_limit)->toBe(['path' => '/login', 'requests' => 20, 'period' => 60, 'action' => 'managed_challenge', 'timeout' => 600])
        ->and($this->zone->refresh()->plan)->toBe('pro');

    $this->limits->set($domain->refresh(), null);
    expect(array_column($this->cf->rateLimits[$this->zoneId], 'description'))->toBe(['their rule'])
        ->and($domain->refresh()->cloudflare_rate_limit)->toBeNull();
});

it('keeps to the Free plan: one rule per zone, path only, 10-second window and block', function () {
    $shop = app(AddDomain::class)($this->site, 'shop.example.com');
    $api = app(AddDomain::class)($this->site, 'api.example.com');

    expect(fn () => $this->limits->set($shop, [...RL_RULE, 'period' => 60]))->toThrow(ValidationException::class, 'Free plan: the window is 10 seconds.');
    expect(fn () => $this->limits->set($shop, [...RL_RULE, 'timeout' => 60]))->toThrow(ValidationException::class, 'Free plan: offenders are blocked for 10 seconds.');

    $this->limits->set($shop, RL_RULE);
    // No host field on Free: the rule covers the path on every proxied name of the zone.
    expect($this->cf->rateLimits[$this->zoneId][0]['expression'])->toBe('(starts_with(http.request.uri.path, "/login"))')
        ->and(CloudflareRateLimits::limits('free')['note'])->toContain('one rule per zone');

    expect(fn () => $this->limits->set($api, RL_RULE))->toThrow(ValidationException::class, 'Free plan: one rate limit rule per zone; another domain of example.com already uses it.');
    expect($api->refresh()->cloudflare_rate_limit)->toBeNull();
});

it('refuses on Free when the zone already has a rule of its own', function () {
    $this->cf->rateLimits[$this->zoneId] = [['id' => 'theirs', 'description' => 'their rule', 'expression' => '(http.request.uri.path eq "/x")', 'action' => 'block', 'enabled' => true]];
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');

    expect(fn () => $this->limits->set($domain, RL_RULE))->toThrow(ValidationException::class, 'example.com already has one of its own');
    expect(array_column($this->cf->rateLimits[$this->zoneId], 'description'))->toBe(['their rule']);
});

it('needs the Cloudflare proxy, and the rule follows the proxy switch', function () {
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');
    $domain->forceFill(['cloudflare_proxied' => false])->save();

    expect(fn () => $this->limits->set($domain, RL_RULE))->toThrow(ValidationException::class, 'Kiln’s edge (Caddy) has no rate limiting');

    $domain->forceFill(['cloudflare_proxied' => true])->save();
    $this->limits->set($domain, RL_RULE);
    expect($this->cf->rateLimits[$this->zoneId])->toHaveCount(1);

    // Grey cloud: the rule leaves the zone (it would never apply), the setting stays for when it is proxied again.
    $domain->forceFill(['cloudflare_proxied' => false])->save();
    $this->limits->resyncFor($this->org, 'shop.example.com');
    expect($this->cf->rateLimits[$this->zoneId])->toBe([])
        ->and($domain->refresh()->cloudflare_rate_limit)->not->toBeNull();

    $domain->forceFill(['cloudflare_proxied' => true])->save();
    $this->zone->forceFill(['rate_limited' => true])->save();
    $this->limits->resyncFor($this->org, 'shop.example.com');
    expect($this->cf->rateLimits[$this->zoneId])->toHaveCount(1);
});

it('removes a domain’s rule with the domain, and leaves zones without Kiln rules alone', function () {
    $other = app(AddDomain::class)($this->site, 'blog.example.com');
    app(RemoveDomain::class)($other);
    expect(array_filter($this->cf->calls, fn (string $call) => str_contains($call, 'http_ratelimit')))->toBe([]);

    $domain = app(AddDomain::class)($this->site, 'shop.example.com');
    $this->limits->set($domain, RL_RULE);
    app(RemoveDomain::class)($domain->refresh());

    expect($this->cf->rateLimits[$this->zoneId])->toBe([])
        ->and($this->zone->refresh()->rate_limited)->toBeFalse();
});

it('sets, shows and removes a domain’s rate limit over HTTP, for members who manage the edge', function () {
    [, $organization] = actingAsMember(Role::Admin);
    $web = edge_server($this->servers, $organization->id, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-9', 'ipv4' => '203.0.113.20']);
    $site = edge_site($this->sites, $organization->id, [$web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'store']);
    $connections = app(CloudflareConnections::class);
    $connections->enable($connections->connect($organization->id, 'Cloudflare', $this->cf->validToken, null), $this->zoneId, true);
    $domain = app(AddDomain::class)($site, 'store.example.com');
    $url = "/sites/{$site->id}/domains/{$domain->id}/rate-limit";

    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.rule', null)
        ->assertJsonPath('data.proxied', true)
        ->assertJsonPath('data.limits.plan', 'free')
        ->assertJsonPath('data.limits.periods', [10]);

    $this->putJson($url, [...RL_RULE, 'period' => 60])->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->putJson($url, RL_RULE)->assertOk()->assertJsonPath('data.rule.requests', 20);
    // API v1 takes the domain's name too.
    $this->getJson("/api/v1/sites/{$site->id}/domains/store.example.com/rate-limit")->assertOk()->assertJsonPath('data.rule.path', '/login');

    $this->deleteJson($url)->assertOk()->assertJsonPath('data.rule', null);
    expect($this->cf->rateLimits[$this->zoneId])->toBe([]);

    actingAsMember(Role::Viewer, $organization);
    $this->putJson($url, RL_RULE)->assertForbidden();
});

it('reports a token without the Zone WAF permission and keeps nothing', function () {
    $this->cf->wafDenied = true;
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');

    expect(fn () => $this->limits->set($domain, RL_RULE))->toThrow(CloudflareError::class, 'request is not authorized');
    expect($domain->refresh()->cloudflare_rate_limit)->toBeNull();
});
