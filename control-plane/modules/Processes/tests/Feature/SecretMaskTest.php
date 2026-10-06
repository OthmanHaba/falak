<?php

use Falak\Identity\Contracts\Role;
use Falak\Processes\Application\ServerConverger;
use Falak\Processes\Domain\Models\Worker;

require_once __DIR__.'/../Support/helpers.php';

it('names the secret variables of each supervised program so the agent masks its logs', function () {
    [, $organization] = actingAsMember(Role::Developer);
    $agents = processes_fake_agents();
    $server = processes_server($organization->id, 'web1');
    $site = processes_site($organization->id, [$server], ['slug' => 'shop', 'laravel' => ['scheduler' => false, 'horizon' => true]], deployed: false);
    Worker::query()->create(['organization_id' => $organization->id, 'site_id' => $site->id, 'queue' => 'default']);
    processes_deploy($site, [$server], ['APP_ENV' => 'production', 'DB_PASSWORD' => 'pw-123456', 'REDIS_URL' => 'redis://:pw@cache:6379']);

    app(ServerConverger::class)->converge($server->id);

    $programs = processes_programs($agents->last('proc.apply', $server->id));
    expect($programs)->not->toBeEmpty();

    foreach ($programs as $program) {
        expect($program['mask'])->toBe(['DB_PASSWORD', 'REDIS_URL'])
            ->and(json_encode($program['mask']))->not->toContain('pw-123456');
    }
});
