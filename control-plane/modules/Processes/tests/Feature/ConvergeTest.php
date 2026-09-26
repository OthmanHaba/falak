<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Kiln\Identity\Contracts\Role;
use Kiln\Processes\Application\Jobs\ConvergeServer;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Processes\Domain\Enums\ApplyStatus;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Processes\Domain\Models\ServerState;
use Kiln\Processes\Domain\Models\Worker;
use Kiln\Processes\Events\ProcessesRestarted;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetReady;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = processes_fake_agents();
    $this->web1 = processes_server($this->organization->id, 'web1');
    $this->web2 = processes_server($this->organization->id, 'web2');
    $this->converger = app(ServerConverger::class);
});

function processes_worker(string $organizationId, string $siteId, array $attributes = []): Worker
{
    return Worker::query()->create(['organization_id' => $organizationId, 'site_id' => $siteId, 'queue' => 'default', ...$attributes]);
}

it('compiles schema-valid proc.apply and cron.apply for mixed sites on one server', function () {
    $shop = processes_site($this->organization->id, [$this->web1, $this->web2], [
        'slug' => 'shop',
        'laravel' => ['scheduler' => true, 'horizon' => true, 'octane' => true],
    ]);
    $blog = processes_site($this->organization->id, [$this->web2, $this->web1], ['slug' => 'blog', 'runtime' => SiteRuntime::PhpFpm, 'framework' => Framework::Symfony, 'php_version' => '8.3']);
    $api = processes_site($this->organization->id, [$this->web1], ['slug' => 'api', 'runtime' => SiteRuntime::Bun, 'framework' => Framework::Node, 'php_version' => null, 'app_port' => 3001]);
    $container = processes_site($this->organization->id, [$this->web1], ['slug' => 'box', 'runtime' => SiteRuntime::Docker, 'framework' => Framework::Docker, 'php_version' => null]);
    $pending = processes_site($this->organization->id, [$this->web1], ['slug' => 'pending'], TargetStatus::Provisioning);

    $worker = processes_worker($this->organization->id, $shop->id, [
        'connection' => 'redis', 'queue' => 'high,default', 'processes' => 3, 'timeout' => 90, 'tries' => 5, 'max_jobs' => 500, 'max_time' => 3600, 'memory' => 256,
        'env' => ['FOO' => 'bar'],
    ]);
    $bunWorker = processes_worker($this->organization->id, $api->id, ['command' => 'bun run worker', 'processes' => 2]);
    $daemon = Daemon::query()->create(['organization_id' => $this->organization->id, 'site_id' => $blog->id, 'name' => 'Consumer', 'command' => 'bin/console messenger:consume async', 'instances' => 2, 'restart' => 'on-failure', 'stop_signal' => 'INT', 'stop_timeout' => 45]);
    $cron = Schedule::query()->create(['organization_id' => $this->organization->id, 'site_id' => $blog->id, 'name' => 'Cleanup', 'command' => 'bin/console app:cleanup', 'expression' => '*/15 * * * *', 'timezone' => 'Europe/Berlin']);
    processes_worker($this->organization->id, $container->id, ['command' => 'never']);
    processes_worker($this->organization->id, $pending->id);

    $this->converger->converge($this->web1->id);

    $proc = $this->agents->last('proc.apply', $this->web1->id);
    $cronApply = $this->agents->last('cron.apply', $this->web1->id);
    expect(processes_schema_errors($proc))->toBe([])->and(processes_schema_errors($cronApply))->toBe([]);

    $programs = processes_programs($proc);
    $workerName = 'shop.worker-'.strtolower(substr($worker->id, -8));
    $bunName = 'api.worker-'.strtolower(substr($bunWorker->id, -8));
    $daemonName = 'blog.daemon-'.strtolower(substr($daemon->id, -8));

    expect(array_keys($programs))->toBe(collect(['api.app', 'shop.horizon', 'shop.octane', $workerName, $bunName, $daemonName])->sort()->values()->all())
        // The bun site's own web process: its start script, bound to the proxied app port.
        ->and($programs['api.app']['command'])->toBe(['bun', 'run', 'start'])
        ->and($programs['api.app']['env'])->toMatchArray(['PORT' => '3001', 'HOST' => '127.0.0.1', 'NODE_ENV' => 'production'])
        ->and($programs['shop.horizon']['command'])->toBe(['php8.4', 'artisan', 'horizon'])
        ->and($programs['shop.horizon']['user'])->toBe('shop')
        ->and($programs['shop.horizon']['cwd'])->toBe('/srv/kiln/sites/shop/current')
        ->and($programs['shop.horizon']['site'])->toBe('shop')
        ->and($programs['shop.octane']['command'])->toContain('octane:start', '--server=frankenphp', '--host=127.0.0.1')
        ->and($programs[$workerName]['command'])->toBe(['php8.4', 'artisan', 'queue:work', 'redis', '--queue=high,default', '--sleep=3', '--tries=5', '--timeout=90', '--memory=256', '--max-jobs=500', '--max-time=3600'])
        ->and($programs[$workerName]['numprocs'])->toBe(3)
        ->and($programs[$workerName]['stop_timeout_s'])->toBe(105)
        ->and($programs[$workerName]['env'])->toMatchArray(['FOO' => 'bar', 'KILN_SITE_ID' => strtoupper($shop->id), 'KILN_SERVER_ID' => strtoupper($this->web1->id)])
        ->and($programs[$bunName]['command'])->toBe(['/bin/bash', '-c', 'bun run worker'])
        ->and($programs[$daemonName])->toMatchArray(['command' => ['/bin/bash', '-c', 'bin/console messenger:consume async'], 'numprocs' => 2, 'restart' => 'on-failure', 'stop_signal' => 'INT', 'stop_timeout_s' => 45, 'user' => 'blog', 'cwd' => '/srv/kiln/sites/blog/current']);

    // web1 leads shop (scheduler) but not blog (custom jobs run on the leader only).
    $jobs = processes_programs($cronApply);
    expect(array_keys($jobs))->toBe(['shop.schedule'])
        ->and($jobs['shop.schedule'])->toMatchArray(['schedule' => '* * * * *', 'command' => 'php8.4 artisan schedule:run', 'user' => 'shop', 'heartbeat' => true, 'site' => 'shop'])
        ->and($jobs['shop.schedule']['env']['KILN_SITE_ID'])->toBe(strtoupper($shop->id));

    $this->converger->converge($this->web2->id);
    $web2Jobs = processes_programs($this->agents->last('cron.apply', $this->web2->id));
    $web2Programs = processes_programs($this->agents->last('proc.apply', $this->web2->id));

    expect(array_keys($web2Jobs))->toBe(['blog.cron-'.strtolower(substr($cron->id, -8))])
        ->and($web2Jobs['blog.cron-'.strtolower(substr($cron->id, -8))])->toMatchArray(['schedule' => '*/15 * * * *', 'timezone' => 'Europe/Berlin', 'overlap' => 'skip', 'command' => 'bin/console app:cleanup'])
        ->and($web2Programs)->toHaveKeys(['shop.horizon', $daemonName])
        ->and($web2Programs)->not->toHaveKey($bunName);
});

it('turns the stored Sites Laravel toggles into programs and schedules', function () {
    $site = processes_site($this->organization->id, [$this->web1]);

    $this->put("/sites/{$site->id}/laravel", ['scheduler' => true, 'horizon' => true, 'octane' => false, 'maintenance' => false])->assertSessionHasNoErrors();

    expect(array_keys(processes_programs($this->agents->last('proc.apply'))))->toBe(['shop.horizon'])
        ->and(array_keys(processes_programs($this->agents->last('cron.apply'))))->toBe(['shop.schedule']);

    $this->put("/sites/{$site->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => true, 'maintenance' => false])->assertSessionHasNoErrors();

    $octane = processes_programs($this->agents->last('proc.apply'));
    expect(array_keys($octane))->toBe(['shop.octane'])
        ->and($octane['shop.octane']['command'])->toContain('--server=frankenphp')
        // Removing the scheduler sends the (now empty) full schedule set.
        ->and($this->agents->last('cron.apply')['payload'])->toBe(['jobs' => []]);
});

it('uses swoole for Octane on php-fpm sites and the site app port when set', function () {
    processes_site($this->organization->id, [$this->web1], ['runtime' => SiteRuntime::PhpFpm, 'app_port' => 8123, 'laravel' => ['octane' => true]]);

    $this->converger->converge($this->web1->id);

    expect(processes_programs($this->agents->last('proc.apply'))['shop.octane']['command'])->toBe(['php8.4', 'artisan', 'octane:start', '--server=swoole', '--host=127.0.0.1', '--port=8123']);
});

it('debounces convergence into one unique job per server', function () {
    Queue::fake();

    $this->converger->schedule($this->web1->id, $this->web1->id);
    $this->converger->schedule($this->web1->id, $this->web2->id);

    Queue::assertPushed(ConvergeServer::class, 2);
    Queue::assertPushed(ConvergeServer::class, fn (ConvergeServer $job) => $job->serverId === $this->web1->id && $job->delay !== null);
});

it('skips identical state and retries after failures', function () {
    $site = processes_site($this->organization->id, [$this->web1], ['laravel' => ['horizon' => true]]);

    $this->converger->converge($this->web1->id);
    $this->converger->converge($this->web1->id);
    expect($this->agents->dispatched('proc.apply'))->toHaveCount(1);

    // Nothing was ever scheduled on the server: no cron.apply at all.
    $this->agents->assertNothingDispatched('cron.apply');

    $this->agents->succeed($this->agents->last('proc.apply')['handle'], ['changed' => true, 'started' => ['shop.horizon']]);
    $state = ServerState::query()->find($this->web1->id);
    expect($state->proc_status)->toBe(ApplyStatus::Applied)->and($state->applied_programs)->toBe(['shop.horizon']);

    $this->converger->converge($this->web1->id);
    expect($this->agents->dispatched('proc.apply'))->toHaveCount(1);

    processes_worker($this->organization->id, $site->id);
    $this->converger->converge($this->web1->id);
    expect($this->agents->dispatched('proc.apply'))->toHaveCount(2);

    $this->agents->fail($this->agents->last('proc.apply')['handle'], 'user shop does not exist');
    expect(ServerState::query()->find($this->web1->id))->proc_status->toBe(ApplyStatus::Failed)->proc_error->toBe('user shop does not exist');

    $this->converger->converge($this->web1->id);
    expect($this->agents->dispatched('proc.apply'))->toHaveCount(3);
});

it('records an offline agent and delivers once it is back', function () {
    processes_site($this->organization->id, [$this->web1], ['laravel' => ['horizon' => true]]);
    $this->agents->unavailable($this->web1->id);

    $this->converger->converge($this->web1->id);
    expect(ServerState::query()->find($this->web1->id))->proc_status->toBe(ApplyStatus::Error);

    $this->agents->available($this->web1->id);
    $this->converger->converge($this->web1->id);
    expect($this->agents->dispatched('proc.apply'))->toHaveCount(1);
});

it('stops a deleted site\'s programs and forgets its processes', function () {
    $site = processes_site($this->organization->id, [$this->web1], ['laravel' => ['horizon' => true]]);
    processes_worker($this->organization->id, $site->id);
    $this->converger->converge($this->web1->id);

    $site->delete();
    SiteDeleted::dispatch($site->id, $this->organization->id, 'shop', [$this->web1->id]);

    expect($this->agents->last('proc.apply')['payload'])->toBe(['programs' => []])
        ->and(Worker::query()->count())->toBe(0);
});

it('converges when a site target becomes ready', function () {
    $site = processes_site($this->organization->id, [$this->web1], ['laravel' => ['horizon' => true]], TargetStatus::Provisioning);
    $this->converger->converge($this->web1->id);
    $this->agents->assertNothingDispatched('proc.apply');

    $target = $site->targets->first();
    $target->forceFill(['status' => TargetStatus::Ready])->save();
    SiteTargetReady::dispatch($site->id, $this->organization->id, $this->web1->id, $target->id);

    expect(array_keys(processes_programs($this->agents->last('proc.apply'))))->toBe(['shop.horizon']);
});

it('restarts a site: horizon:terminate for Horizon, proc.restart for the rest', function () {
    Event::fake([ProcessesRestarted::class]);
    $site = processes_site($this->organization->id, [$this->web1, $this->web2], ['laravel' => ['horizon' => true]]);
    $worker = processes_worker($this->organization->id, $site->id);
    $other = processes_site($this->organization->id, [$this->web1], ['slug' => 'other', 'laravel' => ['horizon' => true]]);

    $control = app(ProcessControl::class);
    expect($control->restartForSite($site->id))->toBe([]);

    foreach ([$this->web1, $this->web2] as $server) {
        $this->converger->converge($server->id);
        $this->agents->succeed($this->agents->last('proc.apply', $server->id)['handle'], ['changed' => true]);
    }

    $handles = $control->restartForSite(strtoupper($site->id));

    expect($handles)->toHaveCount(4);
    $exec = $this->agents->last('system.exec', $this->web1->id);
    expect(processes_schema_errors($exec))->toBe([])
        ->and($exec['payload'])->toMatchArray(['script' => 'php8.4 artisan horizon:terminate', 'user' => 'shop', 'cwd' => '/srv/kiln/sites/shop/current'])
        ->and($this->agents->last('proc.restart', $this->web1->id)['payload'])->toBe(['names' => ['shop.worker-'.strtolower(substr($worker->id, -8))], 'site' => 'shop']);

    Event::assertDispatched(ProcessesRestarted::class, fn (ProcessesRestarted $e) => $e->siteId === $site->id && count($e->commandIds) === 4 && $e->serverIds === [$this->web1->id, $this->web2->id]);

    // One server only; other sites are untouched.
    expect($control->restartForSite($site->id, $this->web2->id))->toHaveCount(2)
        ->and(collect($this->agents->dispatched('proc.restart'))->pluck('payload.site')->unique()->all())->toBe(['shop'])
        ->and($other->id)->not->toBeEmpty();
});
