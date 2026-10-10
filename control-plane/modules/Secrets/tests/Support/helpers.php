<?php

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Identity\Domain\Models\Organization;
use Falak\Kernel\Network\EndpointGuard;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Secrets\Application\Actions\CreateSecret;
use Falak\Secrets\Application\Actions\SaveSecretProvider;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\Secret;
use Falak\Secrets\Domain\Models\SecretProvider;
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

function secret_store_deploy(DeployWorld $world): Deployment
{
    $deployment = app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    return $deployment->refresh();
}

/**
 * Provider endpoints resolve to a public address in tests (no DNS), or to the addresses given per host.
 *
 * @param  array<string, list<string>>  $hosts
 */
function secrets_guard(array $hosts = []): void
{
    app()->instance(EndpointGuard::class, new EndpointGuard(fn (string $host) => $hosts[$host] ?? ['93.184.216.34']));
    config(['secrets.providers.retry_delay_ms' => 0]);
}

/**
 * A provider of the organization, saved like the UI does (settings validated, endpoint checked).
 *
 * @param  array<string, string>  $config
 * @param  array<string, mixed>  $attributes  name, allow_private_network, cache_ttl_seconds
 */
function secrets_provider(Organization $organization, ProviderType $type, array $config, array $attributes = []): SecretProvider
{
    return app(SaveSecretProvider::class)($organization->id, null, [
        'name' => $attributes['name'] ?? $type->label(),
        'type' => $type->value,
        'config' => $config,
        ...$attributes,
    ], null);
}

/**
 * A Vault provider with a static token at https://vault.example.com.
 *
 * @param  array<string, string>  $config
 * @param  array<string, mixed>  $attributes
 */
function secrets_vault(Organization $organization, array $config = [], array $attributes = []): SecretProvider
{
    return secrets_provider($organization, ProviderType::Vault, ['address' => 'https://vault.example.com', 'auth_method' => 'token', 'token' => 'hvs.ROOT-TOKEN', ...$config], $attributes);
}

/**
 * A linked secret of the organization, resolved through the provider.
 *
 * @param  array<string, mixed>  $attributes
 */
function secrets_linked(Organization $organization, string $name, string $reference, SecretProvider $provider, array $attributes = []): Secret
{
    return secrets_create($organization, $name, '', attributes: ['kind' => 'linked', 'reference' => $reference, 'provider_id' => $provider->id, ...$attributes]);
}
