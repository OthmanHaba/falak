<?php

use Carbon\CarbonImmutable;
use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Alert;
use Falak\Edge\Application\Jobs\CheckCertificateExpiry;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\Domain;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/helpers.php';

/** AgentDirectory answering with fixed facts per server. */
final class CertExpiryAgents implements AgentDirectory
{
    /** @var array<string, array<string, mixed>> server id => facts */
    public array $facts = [];

    public function forServer(string $serverId): ?AgentInfo
    {
        return $this->forServers([$serverId])[$serverId] ?? null;
    }

    public function forServers(array $serverIds): array
    {
        $out = [];

        foreach ($serverIds as $id) {
            if (isset($this->facts[$id])) {
                $out[$id] = new AgentInfo((string) Str::ulid(), $id, AgentStatus::Online, 'v0.10.0', 'web', 'amd64', $this->facts[$id], [], new DateTimeImmutable, new DateTimeImmutable, null);
            }
        }

        return $out;
    }

    public function metrics(string $serverId, DateTimeInterface $since): array
    {
        return [];
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00 UTC'));
    ['servers' => $servers] = edge_fakes();
    [, $organization] = memberOf();
    $this->org = $organization->id;
    $this->server = edge_server($servers, $this->org);
    $this->second = edge_server($servers, $this->org);
    $this->agents = new CertExpiryAgents;
    app()->instance(AgentDirectory::class, $this->agents);
    $this->siteId = (string) Str::ulid();
    $this->domain = Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->siteId, 'name' => 'shop.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);
    $this->serve = fn (string $notAfter, ?string $serverId = null) => $this->agents->facts[$serverId ?? $this->server->id] = ['tls_certificates' => [['name' => 'shop.com', 'not_after' => $notAfter]]];
});

function expiry_alerts(bool $recovery = false): Collection
{
    return Alert::query()->where('type', 'edge.certificate_expiring')->where('recovery', $recovery)->orderBy('id')->get();
}

it('stays quiet while ACME certificates are more than 14 days from expiry', function () {
    ($this->serve)('2026-11-20T00:00:00Z');
    dispatch_sync(new CheckCertificateExpiry);

    expect(expiry_alerts())->toHaveCount(0);
});

it('alerts at 14, 7 and 1 days, once each, and resolves once on renewal', function () {
    ($this->serve)('2026-10-22T12:00:00Z'); // 12 days
    dispatch_sync(new CheckCertificateExpiry);
    dispatch_sync(new CheckCertificateExpiry);

    $first = expiry_alerts()->sole();
    expect($first->severity)->toBe(Severity::Warning)
        ->and($first->title)->toBe('Certificate for shop.com expires in 12 days')
        ->and($first->url)->toBe(url("/sites/{$this->siteId}/domains"))
        ->and($first->action)->toBe('Inspect certificate')
        ->and($first->body)->toContain('renewal keeps failing');

    $this->travel(6)->days(); // 6 days left
    dispatch_sync(new CheckCertificateExpiry);
    $this->travel(5)->days();
    $this->travel(13)->hours(); // under a day
    dispatch_sync(new CheckCertificateExpiry);

    expect(expiry_alerts()->pluck('severity')->all())->toBe([Severity::Warning, Severity::Critical, Severity::Critical])
        ->and(expiry_alerts()->last()->title)->toBe('Certificate for shop.com expires in less than a day');

    ($this->serve)('2027-01-20T00:00:00Z');
    dispatch_sync(new CheckCertificateExpiry);

    expect(expiry_alerts(recovery: true)->sole()->title)->toBe('Certificate for shop.com renewed');
});

it('takes the earliest expiry among the servers serving the domain', function () {
    ($this->serve)('2027-01-20T00:00:00Z');
    ($this->serve)('2026-10-15T00:00:00Z', $this->second->id);
    dispatch_sync(new CheckCertificateExpiry);

    expect(expiry_alerts()->pluck('severity')->all())->toBe([Severity::Warning, Severity::Critical]);
});

it('ignores certificates of names that are not the organization\'s domains', function () {
    $this->agents->facts[$this->server->id] = ['tls_certificates' => [['name' => 'old-removed.com', 'not_after' => '2026-10-11T00:00:00Z']]];
    [, $other] = memberOf();
    Domain::query()->create(['organization_id' => $other->id, 'site_id' => (string) Str::ulid(), 'name' => 'old-removed.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);

    dispatch_sync(new CheckCertificateExpiry);

    expect(expiry_alerts())->toHaveCount(0);
});

it('alerts on uploaded certificates in use, with the upload as the suggested fix', function () {
    $pem = edge_self_signed(['api.shop.com'], 5);
    $certificate = Certificate::query()->create([
        'organization_id' => $this->org, 'site_id' => $this->siteId, 'name' => 'api-cert', 'domains' => ['api.shop.com'], 'cert_pem' => $pem['cert'], 'key_pem' => $pem['key'],
        'not_after' => now()->addDays(5), 'fingerprint' => str_repeat('f', 64),
    ]);
    $unused = Certificate::query()->create([
        'organization_id' => $this->org, 'name' => 'unused', 'domains' => ['old.shop.com'], 'cert_pem' => $pem['cert'], 'key_pem' => $pem['key'],
        'not_after' => now()->addDays(2), 'fingerprint' => str_repeat('e', 64),
    ]);
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->siteId, 'name' => 'api.shop.com', 'is_primary' => false, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Custom, 'certificate_id' => $certificate->id]);

    dispatch_sync(new CheckCertificateExpiry);

    expect(expiry_alerts())->toHaveCount(2)
        ->and(expiry_alerts()->every(fn (Alert $a) => $a->action === 'Upload renewed certificate' && str_contains($a->title, 'api.shop.com')))->toBeTrue();

    // The domain moves to automatic TLS: the stages clear.
    Domain::query()->where('name', 'api.shop.com')->update(['tls_mode' => TlsMode::Auto->value, 'certificate_id' => null]);
    dispatch_sync(new CheckCertificateExpiry);
    expect(expiry_alerts(recovery: true))->toHaveCount(2)->and($unused->exists)->toBeTrue();
});
