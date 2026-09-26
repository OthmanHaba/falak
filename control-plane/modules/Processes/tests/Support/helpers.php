<?php

use Illuminate\Support\Str;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\LaravelSettings;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
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
function processes_site(string $organizationId, array $servers, array $attributes = [], TargetStatus $status = TargetStatus::Ready): Site
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
        'deploy_script' => '$KILN_FETCH',
        'laravel' => new LaravelSettings,
        'shared_paths' => [],
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

    return $site->load('targets');
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
