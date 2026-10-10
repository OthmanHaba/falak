<?php

use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Edge\Domain\Models\CloudflareZone;
use Falak\Edge\Domain\Models\DnsCredential;
use Falak\Edge\Domain\Models\Domain;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Domain\Models\Project;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Models\Attachment;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';
require_once __DIR__.'/../../../Volumes/tests/Support/helpers.php';

beforeEach(function () {
    databases_fake_dns();
    $this->agents = FakeAgentGateway::install();
    [$this->admin, $this->organization] = actingAsMember(Role::Admin);
    $this->project = Project::query()->where('organization_id', $this->organization->id)->where('is_default', true)->firstOrFail();
    $this->production = projects_default_env($this->organization);
    $this->server = databases_server($this->organization);
    [$this->db, , $this->instance] = projects_database($this->organization, 'shop', $this->production, server: $this->server);
    $this->site = projects_site($this->organization, 'Web', environment: $this->production, servers: [$this->server]);
    $this->volume = volumes_volume($this->organization->id, $this->server, 'uploads', VolumeKind::Docker);
    Attachment::query()->create(['volume_id' => $this->volume->id, 'attachable_type' => AttachableType::Site, 'attachable_id' => $this->site->id, 'mount_path' => '/data']);
});

function readiness(object $test): array
{
    return $test->getJson("/recovery/readiness/{$test->project->id}")->assertOk()->json('data');
}

it('scores a project by its databases\' and volumes\' backups, drills and PITR, with a link per gap', function () {
    $score = readiness($this);
    $problems = collect($score['gaps'])->map(fn ($gap) => "{$gap['kind']}:{$gap['name']}:{$gap['problem']}")->all();

    expect($score)->toMatchArray(['project_id' => $this->project->id, 'score' => 0, 'checks' => 6, 'passed' => 0])
        ->and($problems)->toBe([
            'database:shop:No restorable (encrypted) backup',
            'database:shop:No backup schedule',
            'database:shop:Never restored in a drill',
            'database:shop:Point-in-time recovery is off',
            'volume:uploads:No restorable (encrypted) backup',
            'volume:uploads:No backup schedule',
        ])
        ->and($score['gaps'][0]['url'])->toContain('/service/database/'.$this->db->id.'/backups')
        ->and($score['gaps'][3]['url'])->toEndWith('/settings')
        ->and($score['gaps'][4]['url'])->toBe("/volumes/{$this->volume->id}");

    // A scheduled, drilled backup and PITR close the database's gaps.
    $provider = databases_provider($this->organization);
    $this->post("/databases/databases/{$this->db->id}/backups", ['storage_provider_id' => $provider->id])->assertSessionHasNoErrors();
    $command = $this->agents->last('db.backup');
    $this->agents->succeed($command['handle'], ['size_bytes' => 4000, 'sha256' => hash('sha256', 'dump'), 'location' => 's3://x', 'duration_ms' => 1,
        'plaintext_sha256' => hash('sha256', 'plain'), 'encryption' => 'cp', 'key_id' => $command['payload']['encryption']['key_id'], 'cipher' => 'aes-256-gcm', 'compression' => 'zstd']);
    Backup::query()->update(['verified_at' => now()]);
    BackupSchedule::query()->forceCreate(['organization_id' => $this->organization->id, 'database_instance_id' => $this->instance->id, 'storage_provider_id' => $provider->id,
        'name' => 'nightly', 'cron' => '0 3 * * *', 'encryption_mode' => 'cp', 'enabled' => true]);
    $this->instance->forceFill(['pitr_enabled' => true])->save();

    $score = readiness($this);
    expect($score)->toMatchArray(['score' => 66, 'passed' => 4, 'checks' => 6])
        ->and(collect($score['gaps'])->pluck('kind')->unique()->all())->toBe(['volume']);
});

it('asks for PITR in production only and scores an empty project 100', function () {
    $staging = projects_environment($this->organization, 'staging');
    projects_database($this->organization, 'scratch', $staging, server: $this->server);

    $gaps = collect(readiness($this)['gaps'])->where('name', 'scratch')->pluck('problem')->all();
    expect($gaps)->not->toContain('Point-in-time recovery is off')->toContain('No backup schedule');

    $empty = Project::query()->forceCreate(['organization_id' => $this->organization->id, 'name' => 'Empty', 'is_default' => false]);
    $this->getJson("/recovery/readiness/{$empty->id}")->assertOk()->assertJsonPath('data.score', 100)->assertJsonPath('data.checks', 0);
});

it('lets every member view readiness, and only their own organization\'s', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->getJson("/recovery/readiness/{$this->project->id}")->assertOk();
    $this->get('/recovery/readiness')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Recovery/Readiness', false)->has('projects', 1));

    actingAsMember(Role::Owner);
    $this->getJson("/recovery/readiness/{$this->project->id}")->assertNotFound();
});

it('tells which domains Falak keeps the DNS of', function () {
    $credential = DnsCredential::query()->forceCreate(['organization_id' => $this->organization->id, 'provider' => 'cloudflare', 'name' => 'cf', 'api_token' => 'x']);
    CloudflareZone::query()->forceCreate(['organization_id' => $this->organization->id, 'dns_credential_id' => $credential->id, 'zone_id' => 'z1', 'name' => 'example.com', 'proxied' => false]);
    foreach (['www.example.com', 'shop.other.net'] as $name) {
        Domain::query()->forceCreate(['organization_id' => $this->organization->id, 'site_id' => $this->site->id, 'name' => $name, 'is_primary' => $name === 'www.example.com', 'www_redirect' => 'none', 'tls_mode' => 'auto']);
    }

    $records = collect(app(DomainRecords::class)->forSites([$this->site->id]))->keyBy('name');
    expect($records['www.example.com']['managed'])->toBeTrue()
        ->and($records['shop.other.net']['managed'])->toBeFalse();
});

it('ships the pages it renders', function (string $page) {
    expect(dirname(__DIR__, 2)."/resources/js/pages/{$page}.tsx")->toBeFile();
})->with(['Settings', 'ServerRecovery', 'Readiness']);
