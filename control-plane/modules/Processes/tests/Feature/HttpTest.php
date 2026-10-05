<?php

use Falak\Identity\Contracts\Role;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\Worker;
use Falak\Sites\Contracts\Framework;
use Falak\Sites\Contracts\SiteRuntime;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = processes_fake_agents();
    $this->server = processes_server($this->organization->id);
    $this->site = processes_site($this->organization->id, [$this->server]);
});

function worker_input(array $overrides = []): array
{
    return [
        'connection' => 'redis', 'queue' => 'high, default', 'processes' => 2, 'timeout' => 60, 'sleep' => 3, 'tries' => 3,
        'backoff' => null, 'max_jobs' => null, 'max_time' => 3600, 'memory' => 256, 'env' => [['key' => 'SECRET', 'value' => 's3cret']], ...$overrides,
    ];
}

it('manages queue workers and keeps env values out of the UI', function () {
    $this->post("/sites/{$this->site->id}/queues", worker_input())->assertSessionHasNoErrors()->assertSessionHas('success');

    $worker = Worker::query()->sole();
    expect($worker->queue)->toBe('high,default')->and($worker->env)->toBe(['SECRET' => 's3cret'])
        ->and(processes_programs($this->agents->last('proc.apply')))->toHaveKey('shop.worker-'.strtolower(substr($worker->id, -8)));

    $state = $this->getJson("/sites/{$this->site->id}/processes")->assertOk()
        ->assertJsonPath('data.laravel.available', true)
        ->assertJsonPath('data.can.manage', true);
    expect(collect($state->json('data.items'))->firstWhere('kind', 'worker')['config']['env'])->toBe([['key' => 'SECRET', 'value' => null]])
        ->and($state->getContent())->not->toContain('s3cret');

    // The classic pages open the canvas panel's Processes tab.
    $this->get("/sites/{$this->site->id}/queues")->assertRedirect();
    $this->get("/sites/{$this->site->id}/processes")->assertRedirect();

    // A row without a value keeps the stored secret.
    $this->put("/sites/{$this->site->id}/queues/{$worker->id}", worker_input(['processes' => 4, 'env' => [['key' => 'SECRET', 'value' => null], ['key' => 'NEW', 'value' => '1']]]))->assertSessionHasNoErrors();
    expect($worker->refresh()->env)->toBe(['NEW' => '1', 'SECRET' => 's3cret'])->and($worker->processes)->toBe(4);

    $this->delete("/sites/{$this->site->id}/queues/{$worker->id}")->assertSessionHasNoErrors();
    expect(Worker::query()->count())->toBe(0);
});

it('requires a start command for workers of non-Laravel sites', function () {
    $node = processes_site($this->organization->id, [$this->server], ['slug' => 'api', 'runtime' => SiteRuntime::Node, 'framework' => Framework::Node, 'php_version' => null]);

    $this->post("/sites/{$node->id}/queues", worker_input())->assertSessionHasErrors('command');
    $this->post("/sites/{$node->id}/queues", worker_input(['command' => 'node worker.js']))->assertSessionHasNoErrors();
});

it('validates workers, daemons and schedules', function () {
    $this->post("/sites/{$this->site->id}/queues", worker_input(['processes' => 0, 'env' => [['key' => '1BAD', 'value' => 'x']], 'server_ids' => ['01J00000000000000000000000']]))
        ->assertSessionHasErrors(['processes', 'env.0.key', 'server_ids.0']);

    $this->post("/sites/{$this->site->id}/daemons", ['name' => 'x', 'command' => 'run', 'user' => 'root', 'directory' => '/srv/../etc', 'instances' => 1, 'restart' => 'sometimes', 'stop_signal' => 'TERM', 'stop_timeout' => 10])
        ->assertSessionHasErrors(['user', 'directory', 'restart']);

    foreach (['* * * *', '@every 10ms', '@reboot', '61 * * * *'] as $bad) {
        $this->post("/sites/{$this->site->id}/scheduler", ['name' => 'x', 'command' => 'run', 'expression' => $bad, 'overlap' => 'skip', 'timeout' => 60, 'heartbeat' => true, 'enabled' => true, 'all_servers' => false])
            ->assertSessionHasErrors('expression');
    }

    expect(Daemon::query()->count() + Schedule::query()->count())->toBe(0);
});

it('manages daemons and scheduled jobs', function () {
    $this->post("/sites/{$this->site->id}/daemons", ['name' => 'Reverb', 'command' => 'php8.4 artisan reverb:start', 'instances' => 1, 'restart' => 'always', 'stop_signal' => 'TERM', 'stop_timeout' => 30])
        ->assertSessionHasNoErrors();
    $this->post("/sites/{$this->site->id}/scheduler", ['name' => 'Report', 'command' => 'php8.4 artisan report', 'expression' => '@every 90s', 'timezone' => 'Europe/Paris', 'overlap' => 'allow', 'timeout' => 600, 'heartbeat' => true, 'enabled' => true, 'all_servers' => false])
        ->assertSessionHasNoErrors();

    $daemon = Daemon::query()->sole();
    $schedule = Schedule::query()->sole();
    $jobs = processes_programs($this->agents->last('cron.apply'));

    expect(processes_programs($this->agents->last('proc.apply')))->toHaveKey('shop.daemon-'.strtolower(substr($daemon->id, -8)))
        ->and($jobs['shop.cron-'.strtolower(substr($schedule->id, -8))])->toMatchArray(['schedule' => '@every 90s', 'timezone' => 'Europe/Paris', 'overlap' => 'allow', 'timeout_s' => 600]);

    $items = collect($this->getJson("/sites/{$this->site->id}/processes")->assertOk()->json('data.items'));
    expect($items->where('kind', 'daemon')->count())->toBe(1)
        ->and($items->firstWhere('kind', 'cron')['config']['expression'])->toBe('@every 90s')
        ->and($items->firstWhere('kind', 'cron')['program'])->toBe('shop.cron-'.strtolower(substr($schedule->id, -8)));

    // Paused jobs leave the schedule set.
    $this->put("/sites/{$this->site->id}/scheduler/{$schedule->id}", ['name' => 'Report', 'command' => 'php8.4 artisan report', 'expression' => '@hourly', 'overlap' => 'skip', 'timeout' => 600, 'heartbeat' => true, 'enabled' => false, 'all_servers' => false])
        ->assertSessionHasNoErrors();
    expect($this->agents->last('cron.apply')['payload'])->toBe(['jobs' => []]);
});

it('lets viewers look but not change, and hides other organizations', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->getJson("/sites/{$this->site->id}/processes")->assertOk()->assertJsonPath('data.can.manage', false);
    $this->post("/sites/{$this->site->id}/queues", worker_input())->assertForbidden();
    $this->post("/sites/{$this->site->id}/processes/restart")->assertForbidden();

    actingAsMember(Role::Owner);
    $this->get("/sites/{$this->site->id}/queues")->assertNotFound();
    $this->getJson("/sites/{$this->site->id}/processes")->assertNotFound();
});

it('refreshes live status for the site programs only', function () {
    processes_worker_for_http($this);
    $this->agents->succeed($this->agents->last('proc.apply')['handle'], ['changed' => true]);

    $response = $this->postJson("/sites/{$this->site->id}/processes/status")->assertOk();
    $id = $response->json('commands.0.id');
    expect(processes_schema_errors($this->agents->last('proc.status')))->toBe([]);

    $this->agents->succeed($id, ['processes' => [
        ['name' => 'shop.horizon', 'instance' => 0, 'state' => 'running', 'pid' => 10, 'restarts' => 0],
        ['name' => 'other.horizon', 'instance' => 0, 'state' => 'running'],
    ]]);

    $this->getJson("/sites/{$this->site->id}/processes/status?commands={$id}")->assertOk()
        ->assertJsonPath('results.0.terminal', true)
        ->assertJsonCount(1, 'results.0.processes')
        ->assertJsonPath('results.0.processes.0.name', 'shop.horizon');

    // A command of another server is not readable through this site.
    $foreign = $this->agents->dispatch(processes_server($this->organization->id)->id, 'proc.status', (object) []);
    $this->getJson("/sites/{$this->site->id}/processes/status?commands={$foreign->id}")->assertJsonCount(0, 'results');

    // The snapshot is kept for the next page load.
    $this->getJson("/sites/{$this->site->id}/processes")->assertJsonPath('data.programs.0.instances.0.state', 'running');
});

it('restarts from the UI with a flash message', function () {
    processes_worker_for_http($this);
    $this->agents->succeed($this->agents->last('proc.apply')['handle'], ['changed' => true]);

    $this->post("/sites/{$this->site->id}/processes/restart")->assertSessionHas('success');
    expect($this->agents->dispatched('system.exec'))->toHaveCount(1);
});

function processes_worker_for_http($test): void
{
    $test->put("/sites/{$test->site->id}/laravel", ['scheduler' => false, 'horizon' => true, 'octane' => false, 'maintenance' => false])->assertSessionHasNoErrors();
}
