<?php

use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\ServerRelease;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\BuildMode;
use Falak\Sites\Contracts\Data\LaravelSettings;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

function processes_server(string $organizationId, string $name = 'web'): Server
{
    return Server::factory()->create(['organization_id' => $organizationId, 'name' => $name.'-'.Str::lower(Str::random(4)), 'type' => ServerType::Web]);
}

/**
 * A site with ready targets (the first server is the leader), created without firing Sites events.
 *
 * @param  list<Server>  $servers
 * @param  array<string, mixed>  $attributes
 */
function processes_site(string $organizationId, array $servers, array $attributes = [], TargetStatus $status = TargetStatus::Ready, bool $deployed = true): Site
{
    $slug = $attributes['slug'] ?? 'shop';

    $site = Site::query()->create([
        'organization_id' => $organizationId,
        'name' => ucfirst($slug),
        'slug' => $slug,
        'runtime' => SiteRuntime::FrankenPhp,
        'build_mode' => BuildMode::Native,
        'framework' => Framework::Laravel,
        'php_version' => '8.4',
        'web_directory' => 'public',
        'unix_user' => $slug,
        'isolated' => true,
        'deploy_script' => '$FALAK_FETCH',
        'laravel' => new LaravelSettings,
        ...$attributes,
    ]);

    foreach (array_values($servers) as $index => $server) {
        SiteTarget::query()->create([
            'site_id' => $site->id,
            'server_id' => $server->id,
            'role' => $index === 0 ? TargetRole::Leader : TargetRole::Member,
            'status' => $status,
        ]);
    }

    if ($deployed) {
        processes_deploy($site, $servers);
    }

    return $site->load('targets');
}

/**
 * Make a release of the site live on the servers, as Deployments does on activation (programs are only
 * supervised once a site has a live release on a server).
 *
 * @param  list<Server>  $servers
 * @param  array<string, string>  $environment  site variables the release was written with
 */
function processes_deploy(Site $site, array $servers, array $environment = []): Release
{
    $release = Release::query()->forceCreate([
        'id' => strtolower((string) Str::ulid()),
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'deployment_id' => strtolower((string) Str::ulid()),
        'status' => 'active',
        'environment' => $environment,
        'activated_at' => now(),
    ]);

    foreach ($servers as $server) {
        ServerRelease::record($site->id, $server->id, $release->id, $release->deployment_id);
    }

    return $release;
}

function processes_fake_agents(): FakeAgentGateway
{
    return FakeAgentGateway::install();
}

/**
 * @param  array{handle: mixed, payload: array<string, mixed>}  $command
 * @return array<string, list<string>>
 */
function processes_schema_errors(array $command): array
{
    return app(ProtocolSchemas::class)->validateCommand($command['handle']->type, ProtocolSchemas::toJson($command['payload']));
}

/**
 * @param  array{payload: array<string, mixed>}  $command
 * @return array<string, array<string, mixed>>
 */
function processes_programs(array $command): array
{
    return collect($command['payload']['programs'] ?? $command['payload']['jobs'])->keyBy('name')->all();
}
