<?php

use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\EndpointGuard;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Sites\Contracts\TargetRole;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

function volumes_server(string $organizationId, string $name = 'app-1'): Server
{
    return Server::factory()->create([
        'organization_id' => $organizationId,
        'name' => $name,
        'type' => ServerType::App,
        'stack' => new Stack(null, [], null, '22', null, null, true),
    ]);
}

/**
 * A docker site running on $servers (leader first).
 *
 * @param  list<Server>  $servers
 */
function volumes_docker_site(string $organizationId, array $servers, string $name = 'Api'): Site
{
    $site = Site::query()->forceCreate([
        'id' => strtolower((string) Str::ulid()),
        'organization_id' => $organizationId,
        'name' => $name,
        'slug' => Str::slug($name).'-'.strtolower(Str::random(4)),
        'runtime' => 'docker',
        'build_mode' => 'docker',
        'framework' => 'docker',
        'unix_user' => 'falak',
        'deploy_script' => '',
        'laravel' => [],
        'app_port' => 3100,
        'docker_image' => 'ghcr.io/acme/api:1',
        'test_domain_enabled' => false,
    ]);

    foreach ($servers as $index => $server) {
        SiteTarget::query()->create(['site_id' => $site->id, 'server_id' => $server->id, 'role' => $index === 0 ? TargetRole::Leader : TargetRole::Member, 'status' => TargetStatus::Ready]);
    }

    return $site;
}

function volumes_volume(string $organizationId, Server $server, string $name = 'data', VolumeKind $kind = VolumeKind::Sized, array $attributes = []): Volume
{
    return Volume::query()->create([
        'organization_id' => $organizationId,
        'server_id' => $server->id,
        'name' => $name,
        'kind' => $kind,
        'docker_name' => $kind === VolumeKind::Docker ? "falak-{$name}" : null,
        'size_limit_bytes' => $kind === VolumeKind::Sized ? 10 * 1024 ** 3 : null,
        'status' => VolumeStatus::Active,
        ...$attributes,
    ]);
}

function volumes_provider(string $organizationId): StorageProvider
{
    // Tests never touch real DNS.
    app()->bind(EndpointGuard::class, fn () => new EndpointGuard(false, fn (string $host) => ['93.184.216.34']));

    return StorageProvider::query()->create([
        'organization_id' => $organizationId,
        'name' => 'Backups '.Str::random(4),
        'driver' => StorageDriver::S3,
        'endpoint' => 'https://s3.eu-central-1.amazonaws.com',
        'region' => 'eu-central-1',
        'bucket' => 'falak-backups',
        'prefix' => 'acme',
        'path_style' => false,
        'access_key_id' => 'AKIAEXAMPLEKEY123456',
        'secret_access_key' => 'super-secret-access-key-value',
    ]);
}

/**
 * @return array<string, list<string>>
 */
function volumes_schema_errors(array $command): array
{
    return app(ProtocolSchemas::class)->validateCommand($command['handle']->type, ProtocolSchemas::toJson($command['payload']));
}

/**
 * Deployments seen by Volumes' redeploys: every site counts as live; queued deploys are recorded.
 */
final class VolumesFakeDeployments implements DeploymentDirectory, DeploymentTrigger
{
    /** @var list<array{site: string, message: ?string}> */
    public array $deployed = [];

    /** @var list<string> sites that never deployed */
    public array $never = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(DeploymentDirectory::class, $fake);
        app()->instance(DeploymentTrigger::class, $fake);

        return $fake;
    }

    public function deploy(string $siteId, ?string $requestedBy = null, ?string $commit = null, ?string $message = null, ?string $author = null): string
    {
        $this->deployed[] = ['site' => $siteId, 'message' => $message];

        return (string) Str::ulid();
    }

    public function currentForSites(array $siteIds): array
    {
        return array_fill_keys(array_values(array_diff($siteIds, $this->never)), true);
    }

    public function recentForSites(array $siteIds, int $limit = 20): array
    {
        return [];
    }

    public function liveCommit(string $siteId): ?string
    {
        return null;
    }
}
