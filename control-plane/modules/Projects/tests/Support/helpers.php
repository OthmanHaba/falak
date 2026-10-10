<?php

use Falak\Databases\Domain\Enums\ResourceStatus;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Grant;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Identity\Domain\Models\Organization;
use Falak\Projects\Application\Actions\CreateEnvironment;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Project;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Contracts\Data\SharedPath;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Sites/tests/Support/helpers.php';
require_once __DIR__.'/../../../Databases/tests/Support/helpers.php';

/**
 * The organization's default project production environment (created with the organization).
 */
function projects_default_env(Organization $organization): Environment
{
    return Project::query()->where('organization_id', $organization->id)->where('is_default', true)->firstOrFail()->production();
}

function projects_environment(Organization $organization, string $name = 'staging', ?Project $project = null): Environment
{
    $project ??= Project::query()->where('organization_id', $organization->id)->where('is_default', true)->firstOrFail();

    return app(CreateEnvironment::class)($project, $name);
}

/**
 * A site row (bypassing CreateSite side effects) with variables and optional ready targets, placed in $environment.
 *
 * @param  array<string, string>  $variables
 * @param  list<Server>  $servers  leader first
 */
function projects_site(Organization $organization, string $name, array $variables = [], ?Environment $environment = null, array $servers = [], array $attributes = [], TargetStatus $targetStatus = TargetStatus::Ready): Site
{
    $site = Site::query()->forceCreate([
        'id' => strtolower((string) Str::ulid()),
        'organization_id' => $organization->id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.strtolower(Str::random(4)),
        'runtime' => 'frankenphp',
        'build_mode' => 'native',
        'framework' => 'laravel',
        'php_version' => '8.4',
        'web_directory' => 'public',
        'unix_user' => 'falak',
        'deploy_script' => "\$FALAK_FETCH\n\$FALAK_ACTIVATE\n",
        'laravel' => ['scheduler' => true],
        'test_domain_enabled' => false,
        ...$attributes,
    ]);

    foreach ($servers as $index => $server) {
        SiteTarget::query()->create([
            'site_id' => $site->id,
            'server_id' => $server->id,
            'role' => $index === 0 ? TargetRole::Leader : TargetRole::Member,
            'status' => $targetStatus,
        ]);
    }

    app(ServiceVolumes::class)->syncSharedPaths($site->organization_id, $site->id, [new SharedPath('storage')]);

    EnvironmentVersion::query()->create([
        'site_id' => $site->id,
        'version' => 1,
        'variables' => $variables,
        'exposed' => [],
        'changed_keys' => [],
        'created_at' => now(),
    ]);

    if ($environment !== null) {
        app(LinkService::class)($environment, ServiceKind::Site, $site->id, $name);
    }

    return $site->refresh();
}

/**
 * An active database with a user granted all privileges in a running database container (its own unless $instance;
 * on $server when given), placed in $environment (the container is then on that environment's network).
 *
 * @return array{0: Database, 1: DatabaseUser, 2: DatabaseInstance}
 */
function projects_database(Organization $organization, string $name = 'app', ?Environment $environment = null, string $engine = 'postgresql', ?DatabaseInstance $instance = null, ?Server $server = null): array
{
    $instance ??= databases_instance($organization, $engine, $server, ['environment_id' => $environment?->id]);
    $database = databases_active_db($instance, $name);
    $keyValue = $instance->engine->isKeyValue();

    $user = $instance->users()->create([
        'organization_id' => $organization->id,
        'server_id' => $instance->server_id,
        'username' => $keyValue ? 'default' : $name.'_user',
        'password' => $keyValue ? $instance->root_password : 'p@ss/word',
        'host' => '%',
        'status' => ResourceStatus::Active,
    ]);
    Grant::query()->create(['user_id' => $user->id, 'database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]);

    if ($environment !== null) {
        app(LinkService::class)($environment, ServiceKind::Database, $database->id, $name);
    }

    return [$database, $user, $instance];
}

function projects_service(string $kind, string $refId): ?Service
{
    return Service::query()->where('kind', $kind)->where('ref_id', $refId)->first();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function projects_deployment(Site $site, string $status, array $attributes = []): Deployment
{
    $number = (int) Deployment::query()->where('site_id', $site->id)->max('number') + 1;

    return Deployment::query()->forceCreate([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'site_slug' => $site->slug,
        'number' => $number,
        'trigger' => 'manual',
        'status' => $status,
        'commit' => str_repeat('b', 40),
        'commit_message' => "Ship it\n\nbody",
        ...$attributes,
    ]);
}

/**
 * @param  list<string>  $statuses  one step per status
 */
function projects_steps(Deployment $deployment, array $statuses): void
{
    foreach ($statuses as $i => $status) {
        DeploymentStep::query()->forceCreate([
            'deployment_id' => $deployment->id,
            'key' => "step-{$i}",
            'kind' => 'hook',
            'phase' => 'prepare',
            'position' => $i,
            'depends_on' => [],
            'status' => $status,
        ]);
    }
}
