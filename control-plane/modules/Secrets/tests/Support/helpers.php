<?php

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Identity\Domain\Models\Organization;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Secrets\Application\Actions\CreateSecret;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';
require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';

/**
 * A secret with its first version, in the given scope (default: the organization).
 *
 * @param  array<string, mixed>  $attributes  CreateSecret data (sensitive, kind, reference, …)
 */
function secrets_create(Organization $organization, string $name, string $value, SecretScope $scope = SecretScope::Organization, ?string $scopeId = null, array $attributes = []): Secret
{
    return app(CreateSecret::class)($organization->id, $scope, $scopeId ?? $organization->id, ['name' => $name, 'value' => $value, ...$attributes], null);
}

/** The project service a site is placed as. */
function secrets_service_of(Site $site): string
{
    return (string) projects_service(ServiceKind::Site->value, $site->id)?->id;
}

/**
 * A DeployWorld whose site is the service "web" of the default environment, with the given variables as version 2.
 *
 * @param  array<string, string>  $variables
 */
function secrets_deploy_world(array $variables): DeployWorld
{
    $world = deploy_world();
    app(LinkService::class)(projects_default_env($world->organization), ServiceKind::Site, $world->site->id, 'web');

    EnvironmentVersion::query()->create([
        'site_id' => $world->site->id,
        'version' => 2,
        'variables' => $variables,
        'exposed' => [],
        'changed_keys' => [],
        'created_at' => now(),
    ]);

    return $world;
}

function secrets_deploy(DeployWorld $world): Deployment
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}
