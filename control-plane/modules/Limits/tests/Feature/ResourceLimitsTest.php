<?php

use Falak\Deployments\Application\Orchestration\StepPayloads;
use Falak\Limits\Contracts\ResourceLimits;
use Symfony\Component\Yaml\Yaml;

/*
| The one limits value and the shape each runtime gets: Docker HostConfig fields, a compose override service, a
| systemd slice and supervisor program fields.
*/

it('maps limits to docker.run / swap / update fields', function () {
    $limits = ResourceLimits::fromArray(['memory_limit' => 512, 'memory_reservation' => 256, 'cpus' => 1.5, 'pids_limit' => 300, 'restart_policy' => 'on-failure',
        'max_restarts' => 5, 'log_max_size' => 20, 'log_max_files' => 3, 'oom' => 'protect', 'bogus' => 1]);

    expect($limits->docker())->toBe([
        'memory_bytes' => 512 * 1024 ** 2,
        'memory_reservation_bytes' => 256 * 1024 ** 2,
        'cpus' => 1.5,
        'pids_limit' => 300,
        'restart_policy' => 'on-failure',
        'max_restarts' => 5,
        'log' => ['max_size_mb' => 20, 'max_files' => 3],
        'oom_score_adj' => -500,
    ])->and($limits->dockerUpdate())->toBe([
        'memory_bytes' => 512 * 1024 ** 2,
        'memory_reservation_bytes' => 256 * 1024 ** 2,
        'cpus' => 1.5,
        'pids_limit' => 300,
        'restart_policy' => 'on-failure',
        'max_restarts' => 5,
    ])->and($limits->summary())->toBe('512 MB · 1.5 CPUs')
        // max_restarts only goes with on-failure; "normal" OOM sends nothing.
        ->and(ResourceLimits::fromArray(['restart_policy' => 'always', 'max_restarts' => 3, 'oom' => 'normal'])->docker())->toBe(['restart_policy' => 'always'])
        ->and(ResourceLimits::fromArray(['memory_limit' => 2048, 'cpus' => 1])->summary())->toBe('2 GB · 1 CPU')
        ->and((new ResourceLimits)->docker())->toBe([])
        ->and((new ResourceLimits)->summary())->toBeNull();
});

it('maps limits to a slice and supervisor program fields', function () {
    $limits = ResourceLimits::fromArray(['memory_limit' => 1000, 'memory_reservation' => 100, 'cpus' => 0.5, 'pids_limit' => 64, 'restart_policy' => 'unless-stopped', 'log_max_size' => 10, 'oom' => 'protect']);

    expect($limits->slice('worker_01j9z8y7x6w5v4t3s2r1q0p9na'))->toBe([
        'name' => 'worker_01j9z8y7x6w5v4t3s2r1q0p9na',
        'memory_max_bytes' => 1000 * 1024 ** 2,
        'memory_high_bytes' => (int) floor(1000 * 1024 ** 2 * 0.9),
        'memory_low_bytes' => 100 * 1024 ** 2,
        'cpu_quota_percent' => 50,
        'tasks_max' => 64,
    ])->and($limits->program())->toBe(['restart' => 'always', 'oom_score_adj' => -500, 'log' => ['max_bytes' => 10 * 1024 ** 2, 'max_files' => 1]])
        ->and(ResourceLimits::sliceName('site', 'my-shop'))->toBe('site_my_shop')
        ->and(ResourceLimits::sliceName('worker', '01J9Z8Y7X6W5V4T3S2R1Q0P9NA'))->toBe('worker_01j9z8y7x6w5v4t3s2r1q0p9na')
        ->and($limits->hasCgroupLimits())->toBeTrue()
        ->and(ResourceLimits::fromArray(['restart_policy' => 'always', 'log_max_size' => 5])->hasCgroupLimits())->toBeFalse();
});

it('tells live-updatable changes from those needing a new container', function () {
    $a = ResourceLimits::fromArray(['memory_limit' => 256, 'cpus' => 1]);

    expect($a->liveUpdatableTo(ResourceLimits::fromArray(['memory_limit' => 512, 'cpus' => 2, 'pids_limit' => 100])))->toBeTrue()
        // A removed memory limit can't be lifted in place, nor can log caps / OOM change.
        ->and($a->liveUpdatableTo(ResourceLimits::fromArray(['cpus' => 1])))->toBeFalse()
        ->and($a->liveUpdatableTo(ResourceLimits::fromArray(['memory_limit' => 256, 'cpus' => 1, 'log_max_size' => 10])))->toBeFalse()
        ->and($a->liveUpdatableTo(ResourceLimits::fromArray(['memory_limit' => 256, 'cpus' => 1, 'oom' => 'protect'])))->toBeFalse()
        ->and(ResourceLimits::fromArray(['cpus' => 1])->withDefaults(ResourceLimits::fromArray(['memory_limit' => 512, 'cpus' => 2]))->toArray())->toBe(['memory_limit' => 512, 'cpus' => 1.0]);
});

it('renders the compose.falak.yaml override with each service’s limits next to the environment network (golden)', function () {
    $yaml = <<<'YAML'
    services:
      web:
        image: nginx
        mem_limit: 2g
      worker:
        image: app
        networks: [backend]
      metrics:
        image: exporter
        network_mode: host
    networks:
      backend: {}
    YAML;

    $override = StepPayloads::falakOverride($yaml, 'falak-env-01hzyenv000000000000000001', [
        'web' => ResourceLimits::fromArray(['memory_limit' => 512, 'memory_reservation' => 256, 'cpus' => 1.5, 'pids_limit' => 200, 'restart_policy' => 'on-failure', 'max_restarts' => 3, 'log_max_size' => 10, 'log_max_files' => 2, 'oom' => 'protect']),
        'metrics' => ResourceLimits::fromArray(['memory_limit' => 64]),
        'worker' => new ResourceLimits,
    ]);

    expect($override)->toBe(<<<'YAML'
    services:
      web:
        mem_limit: 512M
        memswap_limit: 512M
        mem_reservation: 256M
        cpus: '1.5'
        pids_limit: 200
        deploy:
          resources:
            limits:
              memory: 512M
              cpus: '1.5'
              pids: 200
            reservations:
              memory: 256M
        restart: 'on-failure:3'
        logging:
          driver: json-file
          options:
            max-size: 10m
            max-file: '2'
        oom_score_adj: -500
        networks:
          default: {}
          falak-env-01hzyenv000000000000000001: {}
      worker:
        networks:
          falak-env-01hzyenv000000000000000001: {}
      metrics:
        mem_limit: 64M
        memswap_limit: 64M
        deploy:
          resources:
            limits:
              memory: 64M
    networks:
      falak-env-01hzyenv000000000000000001:
        external: true

    YAML);

    // Without a network, only services with limits; nothing at all: no override.
    expect(Yaml::parse((string) StepPayloads::falakOverride($yaml, null, ['metrics' => ResourceLimits::fromArray(['cpus' => 0.5])])))
        ->toBe(['services' => ['metrics' => ['cpus' => '0.5', 'deploy' => ['resources' => ['limits' => ['cpus' => '0.5']]]]]])
        ->and(StepPayloads::falakOverride($yaml, null, ['web' => new ResourceLimits]))->toBeNull();
});
