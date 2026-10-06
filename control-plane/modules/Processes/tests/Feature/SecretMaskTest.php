<?php

use Falak\Fleet\Events\AgentSecretsMissing;
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

it('names cron jobs\' secrets and re-sends a rebooted server its programs and jobs', function () {
    [, $organization] = actingAsMember(Role::Developer);
    $agents = processes_fake_agents();
    $server = processes_server($organization->id, 'web1');
    $site = processes_site($organization->id, [$server], ['slug' => 'shop', 'laravel' => ['scheduler' => true, 'horizon' => true]], deployed: false);
    processes_deploy($site, [$server], ['APP_ENV' => 'production', 'DB_PASSWORD' => 'pw-123456']);
    app(ServerConverger::class)->converge($server->id);

    $jobs = processes_programs($agents->last('cron.apply', $server->id));
    expect($jobs['shop.schedule']['mask'])->toBe(['DB_PASSWORD']);
    $before = [count($agents->dispatched('proc.apply', $server->id)), count($agents->dispatched('cron.apply', $server->id))];

    // Another server's site, or a site this server doesn't run: nothing to send.
    AgentSecretsMissing::dispatch('agent', $organization->id, $server->id, ['blog']);
    expect([count($agents->dispatched('proc.apply', $server->id)), count($agents->dispatched('cron.apply', $server->id))])->toBe($before);

    // The agent lost the secrets: the unchanged state is sent again (forced), once per throttle window.
    AgentSecretsMissing::dispatch('agent', $organization->id, $server->id, ['shop']);
    AgentSecretsMissing::dispatch('agent', $organization->id, $server->id, ['shop']);
    expect([count($agents->dispatched('proc.apply', $server->id)), count($agents->dispatched('cron.apply', $server->id))])->toBe([$before[0] + 1, $before[1] + 1]);
});
