<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kiln\Edge\Application\Jobs\ApplyEdgeConfig;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\ApplyStatus;
use Kiln\Edge\Domain\Enums\InstallStatus;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\CertificateInstall;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Edge\Domain\Models\Redirect;
use Kiln\Edge\Domain\Models\ServerState;
use Kiln\Edge\Domain\Models\Upstream;
use Kiln\Edge\Events\CertificateIssued;
use Kiln\Edge\Events\EdgeApplied;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Sites\Events\SiteUpdated;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    ['sites' => $this->sites, 'servers' => $this->servers, 'agents' => $this->agents] = edge_fakes();
    $this->org = (string) Str::ulid();
    $this->web = edge_server($this->servers, $this->org);
    $this->site = edge_site($this->sites, $this->org, [$this->web->id]);
    Domain::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'shop.com', 'is_primary' => true, 'www_redirect' => WwwRedirect::None, 'tls_mode' => TlsMode::Auto]);
    $this->routes = app(EdgeRoutes::class);
});

function edge_finish(string $org, string $serverId, string $commandId, string $type, array $result = ['changed' => true, 'config_sha256' => null, 'routes' => 1]): void
{
    $result['config_sha256'] ??= str_repeat('c', 64);
    event(new CommandFinished($commandId, $org, $serverId, $type, 'key', 0, $result));
}

it('dispatches edge.caddy.apply once per distinct config', function () {
    $handle = $this->routes->apply($this->web->id);

    expect($handle)->not->toBeNull()
        ->and($this->agents->ofType('edge.caddy.apply'))->toHaveCount(1)
        ->and($this->agents->dispatched[0]['handle']->idempotencyKey)->toStartWith("edge.apply:{$this->web->id}:")
        ->and(ServerState::query()->find($this->web->id))->status->toBe(ApplyStatus::Pending)
        ->and($this->routes->apply($this->web->id))->toBeNull();

    // Forced re-apply always dispatches, with a fresh idempotency key.
    $forced = $this->routes->apply($this->web->id, force: true);
    expect($forced)->not->toBeNull()
        ->and($forced->idempotencyKey)->not->toBe($handle->idempotencyKey)
        ->and($this->agents->ofType('edge.caddy.apply'))->toHaveCount(2);

    // A config change dispatches again.
    Redirect::query()->create(['site_id' => $this->site->id, 'from' => '/a', 'to' => '/b', 'status' => 301]);
    expect($this->routes->apply($this->web->id))->not->toBeNull();
});

it('retries after a failed apply and records unreachable agents', function () {
    $handle = $this->routes->apply($this->web->id);
    event(new CommandFailed($handle->id, $this->org, $this->web->id, 'edge.caddy.apply', 'key', 'failed', 'caddy admin API unreachable', 1));

    expect(ServerState::query()->find($this->web->id))->status->toBe(ApplyStatus::Failed)->error->toBe('caddy admin API unreachable')
        ->and($this->routes->apply($this->web->id))->not->toBeNull();

    $this->agents->offline[] = $this->web->id;
    expect($this->routes->apply($this->web->id, force: true))->toBeNull()
        ->and(ServerState::query()->find($this->web->id))->status->toBe(ApplyStatus::Error);
});

it('leaves servers without routes or http alone', function () {
    $empty = edge_server($this->servers, $this->org);
    $worker = edge_server($this->servers, $this->org, ['type' => ServerType::Worker]);
    edge_site($this->sites, $this->org, [$worker->id]);

    expect($this->routes->apply($empty->id))->toBeNull()
        ->and($this->routes->apply($worker->id))->toBeNull()
        ->and($this->routes->apply('01HUNKNOWN0000000000000000'))->toBeNull()
        ->and($this->agents->dispatched)->toBe([]);
});

it('records the result and announces EdgeApplied', function () {
    Event::fake([EdgeApplied::class]);
    $old = $this->routes->apply($this->web->id);
    $new = $this->routes->apply($this->web->id, force: true);

    edge_finish($this->org, $this->web->id, $old->id, 'edge.caddy.apply'); // superseded: ignored
    expect(ServerState::query()->find($this->web->id)->status)->toBe(ApplyStatus::Pending);

    edge_finish($this->org, $this->web->id, $new->id, 'edge.caddy.apply', ['changed' => true, 'config_sha256' => str_repeat('d', 64), 'routes' => 3]);

    $state = ServerState::query()->find($this->web->id);
    expect($state->status)->toBe(ApplyStatus::Applied)->and($state->routes)->toBe(3)->and($state->applied_at)->not->toBeNull();
    Event::assertDispatched(EdgeApplied::class, fn (EdgeApplied $e) => $e->serverId === $this->web->id && $e->commandId === $new->id && $e->changed && $e->configSha256 === str_repeat('d', 64) && $e->routes === 3 && $e->payloadSha256 === $state->payload_sha256);
});

it('debounces scheduled applies into one unique job per server', function () {
    Queue::fake();

    $this->routes->schedule($this->web->id, $this->web->id);
    $this->routes->schedule($this->web->id);
    $other = edge_server($this->servers, $this->org);
    $this->routes->schedule($other->id);

    Queue::assertPushed(ApplyEdgeConfig::class, 2);
    Queue::assertPushed(ApplyEdgeConfig::class, fn (ApplyEdgeConfig $job) => $job->serverId === $this->web->id && $job->delay !== null);
});

it('runs the scheduled job against the latest state', function () {
    $this->routes->schedule($this->web->id); // sync queue in tests

    [$command] = $this->agents->ofType('edge.caddy.apply');
    expect($command['payload']['sites'][0]['domains'])->toBe(['shop.com']);
});

it('re-applies when sites change', function (Closure $event) {
    $event($this);

    expect($this->agents->ofType('edge.caddy.apply', $this->web->id))->toHaveCount(1);
})->with([
    'created' => [fn ($t) => SiteCreated::dispatch($t->site->id, $t->org, $t->site->slug, 'frankenphp', [$t->web->id])],
    'updated' => [fn ($t) => SiteUpdated::dispatch($t->site->id, $t->org, ['web_directory'], [$t->web->id])],
    'targets' => [fn ($t) => SiteTargetsChanged::dispatch($t->site->id, $t->org, [$t->web->id], [], [$t->web->id], $t->web->id)],
]);

it('forgets deleted sites and reconfigures their servers', function () {
    $lb = edge_server($this->servers, $this->org, ['type' => ServerType::LoadBalancer]);
    LoadBalancer::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'server_id' => $lb->id, 'policy' => 'round_robin', 'backend_port' => 80, 'weights' => []]);
    $this->routes->apply($this->web->id);
    $this->routes->apply($lb->id);
    $this->agents->dispatched = [];

    $this->sites->forget($this->site->id);
    SiteDeleted::dispatch($this->site->id, $this->org, $this->site->slug, [$this->web->id]);

    expect(Domain::query()->count())->toBe(0)
        ->and(LoadBalancer::query()->count())->toBe(0)
        ->and($this->agents->ofType('edge.caddy.apply', $this->web->id)[0]['payload']['sites'])->toBe([])
        ->and($this->agents->ofType('edge.caddy.apply', $lb->id)[0]['payload']['sites'])->toBe([]);
});

it('forgets deleted servers and falls back from their load balancers', function () {
    $lb = edge_server($this->servers, $this->org, ['type' => ServerType::LoadBalancer]);
    LoadBalancer::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'server_id' => $lb->id, 'policy' => 'round_robin', 'backend_port' => 80, 'weights' => []]);
    Upstream::query()->create(['site_id' => $this->site->id, 'server_id' => $lb->id, 'upstream' => '127.0.0.1:1']);
    ServerState::query()->create(['server_id' => $lb->id, 'organization_id' => $this->org, 'status' => ApplyStatus::Applied]);

    ServerDeleted::dispatch($lb->id, $this->org, 'lb', 'lb-1');

    expect(LoadBalancer::query()->count())->toBe(0)
        ->and(ServerState::query()->find($lb->id))->toBeNull()
        ->and(Upstream::query()->count())->toBe(0)
        ->and($this->agents->ofType('edge.caddy.apply', $this->web->id)[0]['payload']['sites'][0]['tls'])->toBe(['mode' => 'acme']);
});

it('records container upstreams and only re-applies on change', function () {
    $this->routes->recordUpstream($this->site->id, $this->web->id, '127.0.0.1:9001');
    $this->routes->recordUpstream($this->site->id, $this->web->id, '127.0.0.1:9001');

    expect(Upstream::query()->where('site_id', $this->site->id)->value('upstream'))->toBe('127.0.0.1:9001')
        ->and($this->agents->ofType('edge.caddy.apply'))->toHaveCount(1);
});

it('marks certificate installs and schedules an apply', function () {
    Event::fake([CertificateIssued::class]);
    $pem = edge_self_signed(['shop.com']);
    $certificate = Certificate::query()->create(['organization_id' => $this->org, 'site_id' => $this->site->id, 'name' => 'kiln-c1', 'domains' => ['shop.com'], 'cert_pem' => $pem['cert'], 'key_pem' => $pem['key'], 'fingerprint' => str_repeat('e', 64)]);
    $install = CertificateInstall::query()->create(['certificate_id' => $certificate->id, 'server_id' => $this->web->id, 'command_id' => '01HCMD00000000000000000000', 'status' => InstallStatus::Pending]);
    Domain::query()->where('name', 'shop.com')->update(['tls_mode' => TlsMode::Custom->value, 'certificate_id' => $certificate->id]);

    event(new CommandFinished('01HCMD00000000000000000000', $this->org, $this->web->id, 'edge.cert.install', 'k', 0, ['changed' => true, 'not_after' => '2027-01-01T00:00:00Z']));

    expect($install->refresh()->status)->toBe(InstallStatus::Installed);
    Event::assertDispatched(CertificateIssued::class, fn (CertificateIssued $e) => $e->certificateId === $certificate->id && $e->notAfter === '2027-01-01T00:00:00Z' && $e->domains === ['shop.com']);

    [$apply] = $this->agents->ofType('edge.caddy.apply');
    expect($apply['payload']['sites'][0]['tls'])->toBe(['mode' => 'custom', 'cert_name' => 'kiln-c1']);

    // Failures from another organization are ignored; own failures are recorded.
    event(new CommandFailed('01HCMD00000000000000000000', (string) Str::ulid(), $this->web->id, 'edge.cert.install', 'k', 'failed', 'boom', 1));
    expect($install->refresh()->status)->toBe(InstallStatus::Installed);
});
