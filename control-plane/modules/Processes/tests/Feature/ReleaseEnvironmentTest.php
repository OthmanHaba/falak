<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Processes\Domain\Models\Worker;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = processes_fake_agents();
    $this->web1 = processes_server($this->organization->id, 'web1');
    $this->web2 = processes_server($this->organization->id, 'web2');
    $this->converger = app(ServerConverger::class);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function release_env_node_site(string $organizationId, array $servers, array $attributes = [], bool $deployed = false)
{
    return processes_site($organizationId, $servers, [
        'slug' => 'api', 'runtime' => SiteRuntime::Node, 'framework' => Framework::Node, 'php_version' => null, 'app_port' => 3001, ...$attributes,
    ], deployed: $deployed);
}

it('does not supervise a never-deployed site\'s programs or schedules until its first release is live', function () {
    $laravel = processes_site($this->organization->id, [$this->web1], ['slug' => 'shop', 'laravel' => ['scheduler' => true, 'horizon' => true]], deployed: false);
    $node = release_env_node_site($this->organization->id, [$this->web1, $this->web2]);
    Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $laravel->id, 'queue' => 'default']);
    Daemon::query()->create(['organization_id' => $this->organization->id, 'site_id' => $node->id, 'name' => 'Consumer', 'command' => 'node consumer.js']);
    Schedule::query()->create(['organization_id' => $this->organization->id, 'site_id' => $node->id, 'name' => 'Report', 'command' => 'node report.js', 'expression' => '0 * * * *']);

    $this->converger->converge($this->web1->id);

    // Nothing to run and never managed: the host is left alone (no crash-looping programs without current/).
    $this->agents->assertNothingDispatched('proc.apply');
    $this->agents->assertNothingDispatched('cron.apply');

    // The node site's first release goes live on web1 only.
    processes_deploy($node, [$this->web1]);
    $this->converger->converge($this->web1->id);
    $this->converger->converge($this->web2->id);

    expect(array_keys(processes_programs($this->agents->last('proc.apply', $this->web1->id))))->toHaveCount(2)
        ->each->toStartWith('api.')
        ->and(array_keys(processes_programs($this->agents->last('cron.apply', $this->web1->id))))->toHaveCount(1);
    expect($this->agents->dispatched('proc.apply', $this->web2->id))->toBe([]);

    processes_deploy($laravel, [$this->web1]);
    $this->converger->converge($this->web1->id);
    expect(array_keys(processes_programs($this->agents->last('proc.apply', $this->web1->id))))->toContain('shop.horizon')
        ->and(array_keys(processes_programs($this->agents->last('cron.apply', $this->web1->id))))->toContain('shop.schedule');
});

it('puts the live release ids and its site variables into every program and job env', function () {
    $site = release_env_node_site($this->organization->id, [$this->web1]);
    $worker = Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'command' => 'node worker.js', 'env' => ['QUEUE' => 'mail', 'DATABASE_URL' => 'postgres://worker']]);
    Schedule::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'name' => 'Report', 'command' => 'node report.js', 'expression' => '0 * * * *']);
    $release = processes_deploy($site, [$this->web1], [
        'DATABASE_URL' => 'postgres://app', 'NODE_ENV' => 'staging', 'PORT' => '9999', 'KILN_RELEASE_ID' => 'stale', 'bad-key' => 'x',
    ]);

    $this->converger->converge($this->web1->id);
    $proc = $this->agents->last('proc.apply', $this->web1->id);
    expect(processes_schema_errors($proc))->toBe([]);
    $programs = processes_programs($proc);

    $ids = [
        'KILN_SITE_ID' => strtoupper($site->id),
        'KILN_SERVER_ID' => strtoupper($this->web1->id),
        'KILN_RELEASE_ID' => strtoupper($release->id),
        'KILN_DEPLOYMENT_ID' => strtoupper($release->deployment_id),
    ];

    expect($programs['api.app']['env'])->toMatchArray([...$ids, 'DATABASE_URL' => 'postgres://app', 'NODE_ENV' => 'staging', 'PORT' => '3001', 'HOST' => '127.0.0.1'])
        ->and($programs['api.app']['env'])->not->toHaveKey('bad-key')
        ->and($programs['api.worker-'.strtolower(substr($worker->id, -8))]['env'])->toMatchArray([...$ids, 'DATABASE_URL' => 'postgres://worker', 'QUEUE' => 'mail']);

    $cron = $this->agents->last('cron.apply', $this->web1->id);
    expect(processes_schema_errors($cron))->toBe([])
        ->and(array_values(processes_programs($cron))[0]['env'])->toMatchArray([...$ids, 'DATABASE_URL' => 'postgres://app']);
});

it('restarts the site on a new release through proc.apply with the new env instead of proc.restart', function () {
    $site = processes_site($this->organization->id, [$this->web1], ['laravel' => ['horizon' => true]]);
    Worker::query()->create(['organization_id' => $this->organization->id, 'site_id' => $site->id, 'queue' => 'default']);
    $control = app(ProcessControl::class);

    foreach ($control->restartForSite($site->id) as $handle) {
        $this->agents->succeed($handle, ['changed' => true]);
    }

    // Same release: the definitions are unchanged, so programs are restarted gracefully.
    expect(array_map(fn ($h) => $h->type, $control->restartForSite($site->id, $this->web1->id)))->toBe(['system.exec', 'proc.restart']);

    // A new release is activated: one proc.apply restarts every program of the site with its ids.
    $next = processes_deploy($site, [$this->web1], ['APP_ENV' => 'production']);
    $handles = $control->restartForSite($site->id, $this->web1->id);

    expect(array_map(fn ($h) => $h->type, $handles))->toBe(['proc.apply']);
    foreach (processes_programs($this->agents->last('proc.apply', $this->web1->id)) as $program) {
        expect($program['env'])->toMatchArray(['KILN_RELEASE_ID' => strtoupper($next->id), 'APP_ENV' => 'production']);
    }
});
