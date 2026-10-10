<?php

use Falak\Edge\Application\Actions\AddDomain;
use Falak\Edge\Application\CloudflareConnections;
use Falak\Edge\Contracts\PreviewDomains;
use Falak\Edge\Domain\Models\Domain;
use Falak\Edge\Domain\Models\PreviewRecord;
use Falak\Edge\Domain\Models\SecurityRule;
use Falak\Edge\Tests\Support\FakeCloudflare;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * The instance's preview domain: the Cloudflare wildcard record, the edge's DNS-01 wildcard certificate, preview
 * hosts on and off the edge server, reserved names and basic auth.
 */

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers] = edge_fakes();
    $this->cf = FakeCloudflare::install();
    $this->zoneId = $this->cf->zone('falak.sh');
    $this->org = strtolower((string) Str::ulid());
    $this->edge = edge_server($this->servers, $this->org, ['id' => strtolower((string) Str::ulid()), 'ipv4' => '203.0.113.10']);
    $this->other = edge_server($this->servers, $this->org, ['id' => strtolower((string) Str::ulid()), 'ipv4' => '203.0.113.20']);
    $this->credential = app(CloudflareConnections::class)->connect($this->org, 'Cloudflare', $this->cf->validToken, null);
    $this->previews = app(PreviewDomains::class);
});

it('points *.<domain> at the edge server and serves its preview hosts with one wildcard certificate', function () {
    $settings = $this->previews->configure($this->org, 'PRV.falak.sh.', $this->credential->id, $this->edge->id);

    expect($settings->domain)->toBe('prv.falak.sh')->and($settings->status)->toBe('active')->and($settings->managedDns)->toBeTrue();
    $wildcard = collect($this->cf->recordsOf($this->zoneId))->firstWhere('name', '*.prv.falak.sh');
    expect($wildcard['type'])->toBe('A')->and($wildcard['content'])->toBe('203.0.113.10')->and($wildcard['proxied'])->toBeFalse();

    $site = edge_site($this->sites, $this->org, [$this->edge->id], ['id' => strtolower((string) Str::ulid())]);
    $this->previews->route($site->id, 'pr-7-web.prv.falak.sh');

    $payload = edge_compile($this->edge->id);
    expect($payload['wildcard_certificates'])->toBe([['subject' => '*.prv.falak.sh', 'dns' => ['provider' => 'cloudflare', 'api_token' => $this->cf->validToken]]])
        ->and(edge_entry($payload, $site->id)['tls'])->toBe(['mode' => 'wildcard'])
        // On the edge itself no per-host record: the wildcard answers.
        ->and(PreviewRecord::query()->count())->toBe(0);

    // Another server compiles neither the certificate nor the token.
    expect(edge_compile($this->other->id))->not->toHaveKey('wildcard_certificates');
});

it('gives a preview on another server its own record and certificate, removed on release', function () {
    $this->previews->configure($this->org, 'prv.falak.sh', $this->credential->id, $this->edge->id);
    $site = edge_site($this->sites, $this->org, [$this->other->id], ['id' => strtolower((string) Str::ulid())]);

    $this->previews->route($site->id, 'pr-8-web.prv.falak.sh');

    $record = collect($this->cf->recordsOf($this->zoneId))->firstWhere('name', 'pr-8-web.prv.falak.sh');
    expect($record['content'])->toBe('203.0.113.20')
        ->and(edge_entry(edge_compile($this->other->id), $site->id)['tls']['mode'])->toBe('acme');

    $this->previews->release($site->id);

    expect(collect($this->cf->recordsOf($this->zoneId))->firstWhere('name', 'pr-8-web.prv.falak.sh'))->toBeNull()
        ->and(PreviewRecord::query()->count())->toBe(0);
});

it('without a managed DNS provider serves previews on the edge server only, with a certificate per live host', function () {
    $settings = $this->previews->configure($this->org, 'prv.example.org', null, $this->edge->id);
    expect($settings->status)->toBe('manual')->and($settings->managedDns)->toBeFalse();

    $site = edge_site($this->sites, $this->org, [$this->edge->id], ['id' => strtolower((string) Str::ulid())]);
    $this->previews->route($site->id, 'pr-9-web.prv.example.org');
    $payload = edge_compile($this->edge->id);
    expect($payload)->not->toHaveKey('wildcard_certificates')
        ->and(edge_entry($payload, $site->id)['tls']['mode'])->toBe('acme');

    $elsewhere = edge_site($this->sites, $this->org, [$this->other->id], ['id' => strtolower((string) Str::ulid())]);
    expect(fn () => $this->previews->route($elsewhere->id, 'pr-9-api.prv.example.org'))->toThrow(ValidationException::class);
});

it('reserves names under the preview domain for previews, and refuses hosts outside it or taken', function () {
    $this->previews->configure($this->org, 'prv.falak.sh', $this->credential->id, $this->edge->id);
    $site = edge_site($this->sites, $this->org, [$this->edge->id], ['id' => strtolower((string) Str::ulid())]);

    expect(fn () => app(AddDomain::class)($site, 'pr-1-web.prv.falak.sh'))->toThrow(ValidationException::class)
        ->and(fn () => $this->previews->route($site->id, 'shop.falak.sh'))->toThrow(ValidationException::class)
        ->and(fn () => $this->previews->route($site->id, 'a.b.prv.falak.sh'))->toThrow(ValidationException::class);

    $this->previews->route($site->id, 'pr-1-web.prv.falak.sh');
    expect($this->previews->available('pr-1-web.prv.falak.sh'))->toBeFalse()
        ->and($this->previews->available('pr-2-web.prv.falak.sh'))->toBeTrue()
        ->and(fn () => $this->previews->route($site->id, 'pr-1-web.prv.falak.sh'))->toThrow(ValidationException::class);
});

it('refuses servers and credentials of another organization, and a domain sites already use', function () {
    $foreign = strtolower((string) Str::ulid());
    $theirs = edge_server($this->servers, $foreign);

    expect(fn () => $this->previews->configure($this->org, 'prv.falak.sh', $this->credential->id, $theirs->id))->toThrow(ValidationException::class)
        ->and(fn () => $this->previews->configure($foreign, 'prv.falak.sh', $this->credential->id, $theirs->id))->toThrow(ValidationException::class);

    $site = edge_site($this->sites, $this->org, [$this->edge->id], ['id' => strtolower((string) Str::ulid())]);
    app(AddDomain::class)($site, 'shop.used.falak.sh');
    expect(fn () => $this->previews->configure($this->org, 'used.falak.sh', $this->credential->id, $this->edge->id))->toThrow(ValidationException::class);
});

it('reports a record Falak did not create instead of overwriting it, and deletes its own on clear', function () {
    $this->cf->put($this->zoneId, ['type' => 'A', 'name' => '*.prv.falak.sh', 'content' => '198.51.100.1']);

    $settings = $this->previews->configure($this->org, 'prv.falak.sh', $this->credential->id, $this->edge->id);
    expect($settings->status)->toBe('error')->and($settings->error)->toContain('did not create');

    $this->cf->records[$this->zoneId] = [];
    $this->previews->configure($this->org, 'prv.falak.sh', $this->credential->id, $this->edge->id);
    expect($this->cf->recordsOf($this->zoneId))->toHaveCount(1);

    $this->previews->clear();
    expect($this->cf->recordsOf($this->zoneId))->toBe([])->and($this->previews->settings())->toBeNull();
});

it('protects a preview with basic auth (hashed)', function () {
    $site = edge_site($this->sites, $this->org, [$this->edge->id], ['id' => strtolower((string) Str::ulid())]);

    $this->previews->protect($site->id, 'preview', 'p4ssw0rd-very-long');

    $rule = SecurityRule::query()->where('site_id', $site->id)->sole();
    expect($rule->username)->toBe('preview')->and($rule->password_hash)->not->toContain('p4ssw0rd')
        ->and(Domain::query()->count())->toBe(0);
});
