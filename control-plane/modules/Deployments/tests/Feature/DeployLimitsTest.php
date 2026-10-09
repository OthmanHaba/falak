<?php

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Domain\Models\ComposeVersion;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/helpers.php';

/*
| Resource limits (Limits) in deploy payloads: a Docker site's HostConfig fields, a compose project's override.
*/

function deploy_limits_schema_errors(array $command): array
{
    return app(ProtocolSchemas::class)->validateCommand($command['handle']->type, ProtocolSchemas::toJson($command['payload']));
}

it('sends a Docker site’s limits in deploy.container.swap', function () {
    $world = deploy_world(site: ['runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'app_port' => 3100, 'deploy_script' => '$FALAK_FETCH',
        'limits' => ['memory_limit' => 768, 'memory_reservation' => 256, 'cpus' => 1.25, 'pids_limit' => 400, 'restart_policy' => 'on-failure', 'max_restarts' => 4, 'log_max_size' => 50, 'log_max_files' => 2, 'oom' => 'protect']]);

    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    $world->builds->succeed();
    deploy_run_all($world->agents);
    $swap = $world->agents->last('deploy.container.swap');

    expect(deploy_limits_schema_errors($swap))->toBe([])
        ->and(array_intersect_key($swap['payload'], array_flip(['memory_bytes', 'memory_reservation_bytes', 'cpus', 'pids_limit', 'restart_policy', 'max_restarts', 'log', 'oom_score_adj'])))->toEqual([
            'memory_bytes' => 768 * 1024 ** 2,
            'memory_reservation_bytes' => 256 * 1024 ** 2,
            'cpus' => 1.25,
            'pids_limit' => 400,
            'restart_policy' => 'on-failure',
            'max_restarts' => 4,
            'log' => ['max_size_mb' => 50, 'max_files' => 2],
            'oom_score_adj' => -500,
        ]);
});

it('writes each compose service’s limits into compose.falak.yaml', function () {
    $world = deploy_world(site: [
        'runtime' => 'compose', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'inline', 'compose_file' => null, 'deploy_script' => '',
        'public_services' => [['service' => 'app', 'port' => 8080, 'domain' => null, 'host_port' => 3000]], 'app_port' => 3000,
        'compose_limits' => ['app' => ['memory_limit' => 256, 'cpus' => 0.5], 'gone' => ['memory_limit' => 64]],
    ]);
    ComposeVersion::query()->create(['site_id' => $world->site->id, 'version' => 1, 'content' => "services:\n  app:\n    image: nginx:1.27\n  redis:\n    image: redis:7\n    mem_limit: 1g\n", 'created_at' => now()]);

    app(TriggerDeployment::class)(app(SiteDirectory::class)->find($world->site->id), Trigger::Manual);
    deploy_run_all($world->agents);
    $up = $world->agents->last('docker.compose.up');
    $files = array_column($up['payload']['files'], 'content', 'name');

    expect(deploy_limits_schema_errors($up))->toBe([])
        ->and(array_keys($files))->toBe(['compose.yaml', 'compose.falak.yaml', '.env'])
        // Only services of the project; one without limits keeps its own (redis' mem_limit).
        ->and(Yaml::parse($files['compose.falak.yaml']))->toBe(['services' => ['app' => [
            'mem_limit' => '256M', 'memswap_limit' => '256M', 'cpus' => '0.5', 'deploy' => ['resources' => ['limits' => ['memory' => '256M', 'cpus' => '0.5']]],
        ]]]);
});
