<?php

use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\Actions\RemoveDomain;
use Falak\Edge\Application\CloudflareConnections;
use Falak\Edge\Application\CloudflareRateLimits;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Infrastructure\Cloudflare\CloudflareError;
use Falak\Edge\Tests\Support\FakeCloudflare;
use Falak\Identity\Contracts\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * Rate limits through Cloudflare (Falak's edge has none): Falak's rules in the zone's http_ratelimit entry point,
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

    $this->limits->set($domain, [...RL_RULE, 'period' => 60, 'timeout' => 600]);

    $rules = $this->cf->rateLimits[$this->zoneId];
    expect(array_column($rules, 'description'))->toBe(['their rule', "falak:ratelimit:{$this->org}:{$domain->id} shop.example.com"])
        ->and($rules[1]['expression'])->toBe('(http.host in {"shop.example.com" "www.shop.example.com"} and starts_with(http.request.uri.path, "/login"))')
        ->and($rules[1]['action'])->toBe('block')
        ->and($rules[1]['ratelimit'])->toBe(['characteristics' => ['cf.colo.id', 'ip.src'], 'period' => 60, 'requests_per_period' => 20, 'mitigation_timeout' => 600])
        ->and($domain->refresh()->cloudflare_rate_limit)->toBe(['path' => '/login', 'requests' => 20, 'period' => 60, 'action' => 'block', 'timeout' => 600])
        ->and($this->zone->refresh()->plan)->toBe('pro');

    $this->limits->set($domain->refresh(), null);
    expect(array_column($this->cf->rateLimits[$this->zoneId], 'description'))->toBe(['their rule'])
        ->and($domain->refresh()->cloudflare_rate_limit)->toBeNull();
});

it('sends a managed challenge without a duration below Enterprise (Cloudflare requires mitigation_timeout 0)', function () {
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');

    // Free: the duration given (even one the plan has no value for) is ignored, the rule throttles per request.
    $this->limits->set($domain, [...RL_RULE, 'action' => 'managed_challenge', 'timeout' => 600]);
    expect($this->cf->rateLimits[$this->zoneId][0]['action'])->toBe('managed_challenge')
        ->and($this->cf->rateLimits[$this->zoneId][0]['ratelimit']['mitigation_timeout'])->toBe(0)
        ->and($domain->refresh()->cloudflare_rate_limit['timeout'])->toBe(0)
        ->and(CloudflareRateLimits::limits('free')['challenge_timeout'])->toBeFalse()
        ->and(CloudflareRateLimits::limits('business')['challenge_timeout'])->toBeFalse();

    // A Block rule still needs a duration the plan allows.
    expect(fn () => $this->limits->set($domain->refresh(), [...RL_RULE, 'timeout' => null]))->toThrow(ValidationException::class, 'Free plan: offenders are blocked for 10 seconds.');

    // Enterprise: a challenge keeps its duration.
    $this->zone->forceFill(['plan' => 'enterprise'])->save();
    $this->limits->set($domain->refresh(), [...RL_RULE, 'period' => 60, 'action' => 'managed_challenge', 'timeout' => 600]);
    expect($this->cf->rateLimits[$this->zoneId][0]['ratelimit']['mitigation_timeout'])->toBe(600)
        ->and(CloudflareRateLimits::limits('enterprise')['challenge_timeout'])->toBeTrue();
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

    expect(fn () => $this->limits->set($domain, RL_RULE))->toThrow(ValidationException::class, 'Falak’s edge (Caddy) has no rate limiting');

    $domain->forceFill(['cloudflare_proxied' => true])->save();
    $this->limits->set($domain, RL_RULE);
    expect($this->cf->rateLimits[$this->zoneId])->toHaveCount(1);

    // Grey cloud: the rule leaves the zone (it would never apply), the setting stays for when it is proxied again.
    $domain->forceFill(['cloudflare_proxied' => false])->save();
    $this->limits->resyncFor($this->org, 'shop.example.com');
    expect($this->cf->rateLimits[$this->zoneId])->toBe([])
        ->and($domain->refresh()->cloudflare_rate_limit)->not->toBeNull();

    // Orange again: the zone has no Falak rule any more (rate_limited false), the domain's stored rule forces a sync.
    $domain->forceFill(['cloudflare_proxied' => true])->save();
    expect($this->zone->refresh()->rate_limited)->toBeFalse();
    $this->limits->resyncFor($this->org, 'shop.example.com');
    expect($this->cf->rateLimits[$this->zoneId])->toBe([]);
    $this->limits->resyncFor($this->org, 'shop.example.com', force: true);
    expect($this->cf->rateLimits[$this->zoneId])->toHaveCount(1)
        ->and($this->zone->refresh()->rate_limited)->toBeTrue();
});

it('restores a domain’s rule when its proxy is switched back on over HTTP', function () {
    [, $organization] = actingAsMember(Role::Admin);
    $web = edge_server($this->servers, $organization->id, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-9', 'ipv4' => '203.0.113.20']);
    $site = edge_site($this->sites, $organization->id, [$web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'store']);
    $connections = app(CloudflareConnections::class);
    $zone = $connections->enable($connections->connect($organization->id, 'Cloudflare', $this->cf->validToken, null), $this->zoneId, true);
    $domain = app(AddDomain::class)($site, 'store.example.com');
    $this->limits->set($domain, RL_RULE);
    $url = "/sites/{$site->id}/domains/{$domain->id}/cloudflare";

    $this->put($url, ['proxied' => false])->assertSessionHasNoErrors();
    expect($this->cf->rateLimits[$this->zoneId])->toBe([])->and($zone->refresh()->rate_limited)->toBeFalse();

    $this->put($url, ['proxied' => true])->assertSessionHasNoErrors();
    expect(array_column($this->cf->rateLimits[$this->zoneId], 'description'))->toBe(["falak:ratelimit:{$organization->id}:{$domain->id} store.example.com"]);
});

it('leaves the rules of other organizations and other Falak installs in a shared zone alone', function () {
    $this->cf->plans[$this->zoneId] = 'business';
    $otherInstall = ['description' => 'falak:ratelimit:'.strtolower((string) Str::ulid()).':'.strtolower((string) Str::ulid()).' api.example.com', 'expression' => '(starts_with(http.request.uri.path, "/"))', 'action' => 'block', 'enabled' => true];
    $unknownLegacy = ['description' => 'falak:ratelimit:'.strtolower((string) Str::ulid()).' old.example.com', 'expression' => '(starts_with(http.request.uri.path, "/old"))', 'action' => 'block', 'enabled' => true];
    $this->cf->rateLimits[$this->zoneId] = [$otherInstall, $unknownLegacy];

    // A second organization of this install, same Cloudflare zone.
    $otherOrg = strtolower((string) Str::ulid());
    $web = edge_server($this->servers, $otherOrg, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-2', 'ipv4' => '203.0.113.11']);
    $otherSite = edge_site($this->sites, $otherOrg, [$web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'blog']);
    $connections = app(CloudflareConnections::class);
    $connections->enable($connections->connect($otherOrg, 'Cloudflare', $this->cf->validToken, null), $this->zoneId, true);
    $blog = app(AddDomain::class)($otherSite, 'blog.example.com');
    $this->limits->set($blog, RL_RULE);

    $shop = app(AddDomain::class)($this->site, 'shop.example.com');
    $this->limits->set($shop, RL_RULE);
    expect(array_column($this->cf->rateLimits[$this->zoneId], 'description'))->toBe([
        $otherInstall['description'], $unknownLegacy['description'], "falak:ratelimit:{$otherOrg}:{$blog->id} blog.example.com", "falak:ratelimit:{$this->org}:{$shop->id} shop.example.com",
    ]);

    // Removing this organization's rule keeps the other organization's.
    $this->limits->set($shop->refresh(), null);
    expect(array_column($this->cf->rateLimits[$this->zoneId], 'description'))->toBe([
        $otherInstall['description'], $unknownLegacy['description'], "falak:ratelimit:{$otherOrg}:{$blog->id} blog.example.com",
    ]);
});

it('takes over this organization’s rules in the first tag format', function () {
    $this->cf->plans[$this->zoneId] = 'pro';
    $shop = app(AddDomain::class)($this->site, 'shop.example.com');
    $this->cf->rateLimits[$this->zoneId] = [['description' => "falak:ratelimit:{$shop->id} shop.example.com", 'expression' => '(starts_with(http.request.uri.path, "/"))', 'action' => 'block', 'enabled' => true]];

    $this->limits->set($shop, [...RL_RULE, 'period' => 60, 'timeout' => 60]);

    expect(array_column($this->cf->rateLimits[$this->zoneId], 'description'))->toBe(["falak:ratelimit:{$this->org}:{$shop->id} shop.example.com"]);
});

it('sends the zone’s own rules back whole, without the fields Cloudflare sets', function () {
    $this->cf->plans[$this->zoneId] = 'pro';
    $theirs = ['id' => 'theirs', 'ref' => 'my-ref', 'version' => '3', 'last_updated' => '2026-09-01T00:00:00Z', 'description' => 'their rule', 'expression' => '(http.request.uri.path eq "/x")', 'action' => 'log', 'logging' => ['enabled' => true], 'enabled' => false];
    $this->cf->rateLimits[$this->zoneId] = [$theirs];
    $shop = app(AddDomain::class)($this->site, 'shop.example.com');

    $this->limits->set($shop, RL_RULE);

    unset($theirs['version'], $theirs['last_updated']);
    expect($this->cf->rateLimits[$this->zoneId][0])->toBe($theirs);
});

it('syncs one zone at a time', function () {
    config(['edge.cloudflare_lock_wait' => 0]);
    $shop = app(AddDomain::class)($this->site, 'shop.example.com');
    $lock = Cache::lock("edge:cloudflare-ratelimit:{$this->zoneId}", 30);
    expect($lock->get())->toBeTrue();

    expect(fn () => $this->limits->set($shop, RL_RULE))->toThrow(ValidationException::class, 'Another rate limit change for example.com is in progress');
    expect($shop->refresh()->cloudflare_rate_limit)->toBeNull()
        ->and($this->cf->rateLimits[$this->zoneId] ?? [])->toBe([]);

    $lock->release();
    $this->limits->set($shop, RL_RULE);
    expect($this->cf->rateLimits[$this->zoneId])->toHaveCount(1);
});

it('refuses a path-less Free rule when the panel is in the zone, and flags zone-wide rules on the other domains', function () {
    [, $organization] = actingAsMember(Role::Admin);
    $web = edge_server($this->servers, $organization->id, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-9', 'ipv4' => '203.0.113.20']);
    $site = edge_site($this->sites, $organization->id, [$web->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'store']);
    $connections = app(CloudflareConnections::class);
    $connections->enable($connections->connect($organization->id, 'Cloudflare', $this->cf->validToken, null), $this->zoneId, true);
    $store = app(AddDomain::class)($site, 'store.example.com');
    $api = app(AddDomain::class)($site, 'api.example.com');

    config(['app.url' => 'https://falak.example.com']);
    expect(fn () => $this->limits->set($store, [...RL_RULE, 'path' => null]))->toThrow(ValidationException::class, 'this panel (falak.example.com) included');
    config(['app.url' => 'https://falak.example.org']);
    $this->limits->set($store, [...RL_RULE, 'path' => null]);
    expect($this->cf->rateLimits[$this->zoneId][0]['expression'])->toBe('(starts_with(http.request.uri.path, "/"))');

    $domains = collect($this->getJson("/sites/{$site->id}/domains")->assertOk()->json('data.domains'))->keyBy('name');
    expect($domains['api.example.com']['cloudflare']['zone_rate_limit'])->toBe(['domain' => 'store.example.com', 'path' => null])
        ->and($domains['store.example.com']['cloudflare']['zone_rate_limit'])->toBeNull();
    $this->getJson("/sites/{$site->id}/domains/{$api->id}/rate-limit")->assertOk()->assertJsonPath('data.zone_rule.domain', 'store.example.com');

    // Paid plans match the host: no zone-wide rule.
    $zone = CloudflareZone::forHost($organization->id, 'api.example.com');
    $zone->forceFill(['plan' => 'pro'])->save();
    $this->getJson("/sites/{$site->id}/domains/{$api->id}/rate-limit")->assertOk()->assertJsonPath('data.zone_rule', null);
});

it('removes a domain’s rule with the domain, and leaves zones without Falak rules alone', function () {
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
