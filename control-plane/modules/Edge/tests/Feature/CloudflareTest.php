<?php

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Application\Actions\AddDomain;
use Kiln\Edge\Application\Actions\RemoveDomain;
use Kiln\Edge\Application\CloudflareConnections;
use Kiln\Edge\Application\CloudflareDns;
use Kiln\Edge\Application\DnsInstructions;
use Kiln\Edge\Application\GeneratedDomains;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Domain\Models\CloudflareZone;
use Kiln\Edge\Domain\Models\DnsRecord;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\OrganizationSetting;
use Kiln\Edge\Infrastructure\Dns\CloudflareRanges;
use Kiln\Edge\Tests\Support\FakeCloudflare;
use Kiln\Fleet\Contracts\AgentUpgrades;
use Kiln\Fleet\Contracts\Data\AgentUpgradeData;
use Kiln\Fleet\Contracts\Data\AgentVersionInfo;
use Kiln\Sites\Contracts\Data\DomainChoice;
use Kiln\Sites\Contracts\DomainType;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Events\SiteTargetsChanged;

/*
 * Cloudflare integration: connection, managed zones, DNS records Kiln creates / updates / removes (its own only),
 * DNS-01 certificates in managed zones, generated names under a zone and trusted proxies. Cloudflare is faked.
 */

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers] = edge_fakes();
    $this->cf = FakeCloudflare::install();
    $this->zoneId = $this->cf->zone('example.com');
    $this->org = strtolower((string) Str::ulid());
    $this->web1 = edge_server($this->servers, $this->org, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-1', 'ipv4' => '203.0.113.10', 'ipv6' => '2001:db8::10']);
    $this->web2 = edge_server($this->servers, $this->org, ['id' => strtolower((string) Str::ulid()), 'name' => 'web-2', 'ipv4' => '203.0.113.11']);
    $this->site = edge_site($this->sites, $this->org, [$this->web1->id], ['id' => strtolower((string) Str::ulid()), 'slug' => 'shop']);
    $this->connections = app(CloudflareConnections::class);
});

function cf_connect(object $test, bool $proxied = true): void
{
    $credential = $test->connections->connect($test->org, 'Cloudflare', $test->cf->validToken, null);
    $test->zone = $test->connections->enable($credential, $test->zoneId, $proxied);
}

it('connects a token after verifying it, and rejects a token Cloudflare refuses', function () {
    expect(fn () => $this->connections->connect($this->org, 'Cloudflare', 'wrong-token-0123456789', null))
        ->toThrow(ValidationException::class);

    $credential = $this->connections->connect($this->org, 'Cloudflare', $this->cf->validToken, null);

    expect($credential->provider)->toBe('cloudflare')
        ->and($credential->account_id)->toBe('acc-1')
        ->and($credential->verified_at)->not->toBeNull()
        ->and($credential->getRawOriginal('api_token'))->not->toBe($this->cf->validToken) // encrypted at rest
        ->and(array_column($this->connections->zones($credential), 'name'))->toBe(['example.com']);
});

it('creates tagged A and AAAA records for a domain in a managed zone', function () {
    cf_connect($this);

    $domain = app(AddDomain::class)($this->site, 'shop.example.com', www: WwwRedirect::ToApex);

    $records = collect($this->cf->recordsOf($this->zoneId));
    expect($records->map(fn ($r) => "{$r['type']} {$r['name']} {$r['content']}")->sort()->values()->all())->toBe([
        'A shop.example.com 203.0.113.10',
        'A www.shop.example.com 203.0.113.10',
        'AAAA shop.example.com 2001:db8::10',
        'AAAA www.shop.example.com 2001:db8::10',
    ])
        ->and($records->every(fn ($r) => $r['proxied'] === true && str_starts_with($r['comment'], "kiln:{$domain->id}")))->toBeTrue()
        ->and(DnsRecord::query()->where('status', DnsRecord::SYNCED)->count())->toBe(4)
        // Let's Encrypt HTTP-01 goes through Cloudflare's proxy; no DNS plugin needed on the servers.
        ->and($domain->refresh()->tls_mode)->toBe(TlsMode::Auto);
});

it('leaves domains outside managed zones alone', function () {
    cf_connect($this);

    $domain = app(AddDomain::class)($this->site, 'shop.other.org');

    expect($this->cf->recordsOf($this->zoneId))->toBe([])
        ->and($domain->tls_mode)->toBe(TlsMode::Auto);
});

it('deletes only its own records when a domain is removed', function () {
    cf_connect($this);
    $theirs = $this->cf->put($this->zoneId, ['type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com']);
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');
    expect($this->cf->recordsOf($this->zoneId))->toHaveCount(3);

    app(RemoveDomain::class)($domain);

    expect(array_keys($this->cf->records[$this->zoneId]))->toBe([$theirs])
        ->and(DnsRecord::query()->count())->toBe(0);
});

it('reports a record it did not create as a conflict and never overwrites it', function () {
    cf_connect($this);
    $this->cf->put($this->zoneId, ['type' => 'A', 'name' => 'shop.example.com', 'content' => '198.51.100.7']);

    app(AddDomain::class)($this->site, 'shop.example.com');

    $record = DnsRecord::query()->where('type', 'A')->sole();
    expect($record->status)->toBe(DnsRecord::CONFLICT)
        ->and($record->error)->toContain('198.51.100.7')
        ->and(collect($this->cf->recordsOf($this->zoneId))->where('type', 'A')->pluck('content')->all())->toBe(['198.51.100.7']);
});

it('follows the site to new servers and drops the records of removed ones', function () {
    cf_connect($this);
    app(AddDomain::class)($this->site, 'shop.example.com');

    edge_site($this->sites, $this->org, [$this->web2->id], ['id' => $this->site->id, 'slug' => 'shop']);
    SiteTargetsChanged::dispatch($this->site->id, $this->org, [$this->web2->id], [$this->web1->id], [$this->web2->id], $this->web2->id);

    expect(collect($this->cf->recordsOf($this->zoneId))->map(fn ($r) => "{$r['type']} {$r['content']}")->all())->toBe(['A 203.0.113.11']);
});

it('switches one domain between proxied and DNS only', function () {
    cf_connect($this);
    $domain = app(AddDomain::class)($this->site, 'shop.example.com');

    $domain->forceFill(['cloudflare_proxied' => false])->save();
    app(CloudflareDns::class)->sync($domain);

    expect(collect($this->cf->recordsOf($this->zoneId))->pluck('proxied')->unique()->all())->toBe([false]);
});

it('generates names under the zone and never manages the panel or agent hosts', function () {
    cf_connect($this);
    OrganizationSetting::for($this->org)->forceFill(['generated_domain_provider' => GeneratedDomains::CLOUDFLARE.'example.com'])->save();

    $name = app(SiteDomains::class)->resolveChoice($this->org, new DomainChoice(DomainType::Generated, null), 'shop-staging', [$this->web1->id], 'domain');
    expect($name)->toBe('shop-staging.example.com');

    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'shop-staging.example.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);
    expect(app(SiteDomains::class)->resolveChoice($this->org, new DomainChoice(DomainType::Generated, null), 'shop-staging', [$this->web1->id], 'domain'))
        ->toBe('shop-staging-2.example.com');

    config(['fleet.api_url' => 'https://agents.example.com/agent/v1']);
    app(AddDomain::class)($this->site, 'agents.example.com');
    expect(collect($this->cf->recordsOf($this->zoneId))->pluck('name')->all())->not->toContain('agents.example.com');
});

it('says Kiln manages the records in the DNS instructions', function () {
    $instructions = DnsInstructions::for('shop.example.com', [], managedZone: 'example.com');

    expect($instructions['managed_by'])->toBe(['provider' => 'cloudflare', 'zone' => 'example.com'])
        ->and($instructions['notes'][0])->toContain('nothing to add by hand');
});

it('trusts Cloudflare for the client IP once a zone is managed', function () {
    app(AddDomain::class)($this->site, 'shop.example.com');
    expect(edge_compile($this->web1->id))->not->toHaveKey('trusted_proxies');

    cf_connect($this);

    expect(edge_compile($this->web1->id)['trusted_proxies'])->toBe(CloudflareRanges::RANGES);
});

it('checks and fixes the zone TLS settings', function () {
    cf_connect($this);

    expect($this->connections->health($this->zone))->toBe([
        'ssl' => ['value' => 'full', 'recommended' => 'strict', 'ok' => false],
        'min_tls_version' => ['value' => '1.0', 'recommended' => '1.2', 'ok' => false],
        'always_use_https' => ['value' => 'off', 'recommended' => 'off', 'ok' => true],
    ]);

    $this->connections->applyRecommended($this->zone, 'ssl');
    $this->connections->applyRecommended($this->zone, 'min_tls_version');

    expect(collect($this->connections->health($this->zone))->every(fn ($s) => $s['ok']))->toBeTrue();
});

it('releases a zone and keeps or deletes its records', function () {
    cf_connect($this);
    app(AddDomain::class)($this->site, 'shop.example.com');

    $this->connections->disable($this->zone, deleteRecords: true);

    expect($this->cf->recordsOf($this->zoneId))->toBe([])
        ->and(CloudflareZone::query()->count())->toBe(0);
});

it('does not send trusted proxies to agents older than 0.3.0 (they reject unknown fields)', function () {
    app(AddDomain::class)($this->site, 'shop.example.com');
    cf_connect($this);
    app()->instance(AgentUpgrades::class, new class implements AgentUpgrades
    {
        public string $version = 'v0.2.8';

        public function versionsFor(array $serverIds): array
        {
            return array_combine($serverIds, array_map(fn ($id) => new AgentVersionInfo($id, $this->version, 'v0.3.0', true), $serverIds));
        }

        public function upgrade(string $serverId, ?string $userId = null): AgentUpgradeData
        {
            throw new RuntimeException('unused');
        }

        public function upgradeOrganization(string $organizationId, ?string $userId = null, ?array $serverIds = null): array
        {
            return [];
        }

        public function outdatedCount(?string $organizationId = null): int
        {
            return 0;
        }
    });

    expect(edge_compile($this->web1->id))->not->toHaveKey('trusted_proxies');

    app(AgentUpgrades::class)->version = 'v0.3.0';
    expect(edge_compile($this->web1->id))->toHaveKey('trusted_proxies');
});
