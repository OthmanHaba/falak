<?php

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\ComposeVersion;
use Falak\Volumes\Contracts\AttachableType;
use Falak\Volumes\Contracts\VolumeKind;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Volume;

require_once __DIR__.'/../Support/helpers.php';

/*
| What Volumes puts into deploy payloads: a classic site's shared paths, a docker site's mounts, a compose stack's
| named volumes recorded when it goes live.
*/

function volume_mounts_deploy(DeployWorld $world)
{
    return app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
}

function volume_mounts_volume(DeployWorld $world, string $name, VolumeKind $kind, int $server = 0): Volume
{
    return Volume::query()->create([
        'organization_id' => $world->organization->id,
        'server_id' => $world->servers[$server]->id,
        'name' => $name,
        'kind' => $kind,
        'docker_name' => $kind === VolumeKind::Docker ? "falak-{$name}" : null,
        'size_limit_bytes' => $kind === VolumeKind::Sized ? 1024 ** 3 : null,
        'status' => VolumeStatus::Active,
    ]);
}

it('sends a classic site’s shared_path volumes as deploy.prepare shared_paths', function () {
    $world = deploy_world();
    volume_mounts_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    expect($world->agents->last('deploy.prepare')['payload']['shared_paths'])->toBe([
        ['path' => 'storage', 'type' => 'dir'],
        ['path' => '.env', 'type' => 'file'],
    ]);
});

it('mounts a docker site’s volumes of each server in deploy.container.swap', function () {
    $world = deploy_world(servers: 2, site: ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3100, 'deploy_script' => '$FALAK_FETCH']);
    $sized = volume_mounts_volume($world, 'uploads', VolumeKind::Sized);
    $docker = volume_mounts_volume($world, 'cache', VolumeKind::Docker);
    $sized->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $world->site->id, 'mount_path' => '/app/uploads']);
    $docker->attachments()->create(['attachable_type' => AttachableType::Site, 'attachable_id' => $world->site->id, 'mount_path' => '/cache', 'read_only' => true]);

    $deployment = volume_mounts_deploy($world);
    $world->builds->succeed();
    deploy_run_all($world->agents);

    [$leader, $member] = [$world->agents->last('deploy.container.swap', $world->servers[0]->id)['payload'], $world->agents->last('deploy.container.swap', $world->servers[1]->id)['payload']];
    expect($deployment->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($leader['volumes'])->toBe([
            ['source' => "/var/lib/falak/volumes/{$sized->id}", 'target' => '/app/uploads'],
            ['source' => 'falak-cache', 'target' => '/cache', 'read_only' => true],
        ])
        // The volumes live on the leader's server: the other server mounts none.
        ->and($member)->not->toHaveKey('volumes');
});

it('records a compose stack’s named volumes when it goes live', function () {
    $world = deploy_world(site: [
        'runtime' => 'compose', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'inline',
        'compose_file' => null, 'deploy_script' => '', 'repository' => null, 'source_connection_id' => null,
    ]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'created_at' => now(),
        'content' => "services:\n  app:\n    image: nginx:1.27\n  redis:\n    image: redis:7\n    volumes: [redis-data:/data]\nvolumes:\n  redis-data:\n"]);

    volume_mounts_deploy($world);
    deploy_run_all($world->agents);
    volume_mounts_deploy($world);
    deploy_run_all($world->agents);

    $volume = Volume::query()->where('kind', VolumeKind::Docker)->sole();
    expect($volume->docker_name)->toBe("{$world->site->slug}_redis-data")
        ->and($volume->server_id)->toBe($world->servers[0]->id)
        ->and(Attachment::query()->where('attachable_type', AttachableType::ComposeService)->get()->map(fn (Attachment $a) => [$a->attachable_type, $a->service, $a->mount_path])->all())
        ->toBe([[AttachableType::ComposeService, 'redis', '/data']])
        // Compose never removes volumes: down keeps them.
        ->and(collect($world->agents->dispatched('docker.compose.up'))->every(fn ($c) => ! array_key_exists('volumes', $c['payload'])))->toBeTrue();
});
