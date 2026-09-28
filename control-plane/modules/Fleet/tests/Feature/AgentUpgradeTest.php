<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Kiln\Fleet\Application\Jobs\SweepFleet;
use Kiln\Fleet\Application\ShippedAgent;
use Kiln\Fleet\Contracts\AgentUpgrades;
use Kiln\Fleet\Contracts\AgentUpgradeStatus;
use Kiln\Fleet\Contracts\Exceptions\AgentUpgradeUnavailable;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\AgentUpgrade;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Fleet\Events\AgentUpgradeFailed;
use Kiln\Fleet\Events\AgentUpgradeSucceeded;
use Kiln\Fleet\Infrastructure\AgentBinaries;

require_once __DIR__.'/../Support/helpers.php';

const UPGRADE_OLD_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

beforeEach(function () {
    $dir = sys_get_temp_dir().'/kiln-agent-bin-'.Str::random(8);
    mkdir($dir);
    file_put_contents("{$dir}/kiln-agent-linux-amd64", 'new agent build');
    file_put_contents("{$dir}/kiln-agent-linux-amd64.version", "v1.1.0\n");
    $this->shippedSha = hash('sha256', 'new agent build');
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test', 'fleet.agent.binaries_path' => $dir, 'fleet.panel_url' => 'https://kiln.example.com',
        'fleet.agent.upgrade.batch_size' => 1]);
    app()->forgetInstance(AgentBinaries::class);
    [, $this->organization] = memberOf();
    $this->events = [];
    Event::listen([AgentUpgradeSucceeded::class, AgentUpgradeFailed::class], fn ($e) => $this->events[] = $e);
});

/**
 * @return array{agent: Agent, serverId: string, headers: array<string, string>}
 */
function upgrade_agent(string $organizationId, array $facts = []): array
{
    $serverId = strtolower((string) Str::ulid());
    $enrolled = fleet_enroll($organizationId, $serverId, ['agent_version' => 'v1.0.0', 'agent_sha256' => UPGRADE_OLD_SHA, ...$facts]);

    return ['agent' => $enrolled['agent'], 'serverId' => $serverId, 'headers' => fleet_mtls($enrolled['fingerprint'])];
}

function upgrade_command(string $serverId): ?Command
{
    return Command::query()->where('server_id', $serverId)->where('type', 'system.upgrade_agent')->latest('queued_at')->first();
}

function upgrade_finish(array $agent, Command $command, array $result = ['changed' => true, 'version' => 'v1.1.0', 'previous_version' => 'v1.0.0'], int $exit = 0, ?string $error = null): void
{
    $event = ['command_id' => $command->id, 'seq' => 0, 'kind' => 'finished', 'at' => now()->toIso8601ZuluString(), 'exit_code' => $exit, 'result' => $result];

    if ($error !== null) {
        $event['error'] = $error;
        unset($event['result']);
    }

    test()->call('POST', "/agent/v1/commands/{$command->id}/events", [], [], [], test()->transformHeadersToServerVars([...$agent['headers'], 'Content-Type' => 'application/x-ndjson']), fleet_ndjson([$event]))->assertNoContent();
}

function upgrade_report(array $agent, string $version, string $sha): void
{
    test()->postJson('/agent/v1/heartbeat', fleet_heartbeat(['facts' => fleet_facts(['agent_version' => $version, 'agent_sha256' => $sha])]), $agent['headers'])->assertNoContent();
}

it('compares running and shipped builds', function (?string $version, ?string $sha, bool $outdated) {
    $shipped = ['version' => 'v1.1.0', 'sha256' => str_repeat('b', 64), 'url' => 'https://x'];

    expect(ShippedAgent::outdated($version, $sha, $shipped))->toBe($outdated);
})->with([
    'older release' => ['v1.0.0', null, true],
    'same release' => ['1.1.0', null, false],
    'newer release than shipped' => ['v1.2.0', str_repeat('c', 64), false],
    'same version, other binary (dev builds)' => ['dev', str_repeat('c', 64), true],
    'same binary' => ['dev', str_repeat('b', 64), false],
    'non-release versions differ' => ['b29b595-dirty', null, true],
    'unknown' => [null, null, false],
]);

it('reports the running and the shipped agent version per server', function () {
    $agent = upgrade_agent($this->organization->id);
    $current = upgrade_agent($this->organization->id, ['agent_version' => 'v1.1.0', 'agent_sha256' => $this->shippedSha]);

    $versions = app(AgentUpgrades::class)->versionsFor([$agent['serverId'], $current['serverId']]);

    expect($versions[$agent['serverId']]->toArray())->toMatchArray(['version' => 'v1.0.0', 'available_version' => 'v1.1.0', 'update_available' => true, 'upgrade' => null])
        ->and($versions[$current['serverId']]->updateAvailable)->toBeFalse()
        ->and(app(AgentUpgrades::class)->outdatedCount($this->organization->id))->toBe(1)
        ->and(app(AgentUpgrades::class)->outdatedCount())->toBe(1);
});

it('upgrades one agent: verified download command, then success once the agent reports the new build', function () {
    $agent = upgrade_agent($this->organization->id);

    $upgrade = app(AgentUpgrades::class)->upgrade($agent['serverId'], 'user-1');
    $command = upgrade_command($agent['serverId']);
    $payload = json_decode($command->payload, true);

    expect($upgrade->status)->toBe(AgentUpgradeStatus::Running)
        ->and($payload)->toBe(['version' => 'v1.1.0', 'url' => 'https://kiln.example.com/install/agent/linux-amd64', 'sha256' => $this->shippedSha])
        ->and(fleet_schema_errors('commands/system.upgrade_agent.schema.json', $payload))->toBe([])
        // Asking again while it runs returns the same upgrade.
        ->and(app(AgentUpgrades::class)->upgrade($agent['serverId'])->id)->toBe($upgrade->id);

    upgrade_finish($agent, $command);
    expect(AgentUpgrade::query()->find($upgrade->id))->status->toBe(AgentUpgradeStatus::Running)->installed->toBeTrue();

    // A heartbeat still from the old process changes nothing; the restarted agent's facts complete it.
    upgrade_report($agent, 'v1.0.0', UPGRADE_OLD_SHA);
    expect(AgentUpgrade::query()->find($upgrade->id)->status)->toBe(AgentUpgradeStatus::Running);
    upgrade_report($agent, 'v1.1.0', $this->shippedSha);

    expect(AgentUpgrade::query()->find($upgrade->id)->status)->toBe(AgentUpgradeStatus::Succeeded)
        ->and($this->events)->toHaveCount(1)
        ->and($this->events[0])->toBeInstanceOf(AgentUpgradeSucceeded::class)
        ->and($this->events[0]->version)->toBe('v1.1.0')
        ->and(app(AgentUpgrades::class)->versionsFor([$agent['serverId']])[$agent['serverId']]->updateAvailable)->toBeFalse();

    expect(fn () => app(AgentUpgrades::class)->upgrade($agent['serverId']))->toThrow(AgentUpgradeUnavailable::class, 'already runs v1.1.0');
});

it('fails and alerts when the agent rejects the build (e.g. checksum mismatch)', function () {
    $agent = upgrade_agent($this->organization->id);
    $upgrade = app(AgentUpgrades::class)->upgrade($agent['serverId']);

    upgrade_finish($agent, upgrade_command($agent['serverId']), exit: 1, error: 'GET https://kiln.example.com/install/agent/linux-amd64: sha256 mismatch');

    $failed = AgentUpgrade::query()->find($upgrade->id);
    expect($failed->status)->toBe(AgentUpgradeStatus::Failed)
        ->and($failed->error)->toContain('sha256 mismatch')
        ->and($this->events[0])->toBeInstanceOf(AgentUpgradeFailed::class)
        ->and($this->events[0]->toAlert()->type)->toBe('fleet.agent_upgrade_failed')
        ->and($this->events[0]->toAlert()->dedupKey)->toBe("fleet.agent_upgrade:{$agent['serverId']}");
});

it('fails an upgrade whose agent does not come back in time', function () {
    $agent = upgrade_agent($this->organization->id);
    $upgrade = app(AgentUpgrades::class)->upgrade($agent['serverId']);
    upgrade_finish($agent, upgrade_command($agent['serverId']));

    $this->travel(11)->minutes();
    upgrade_report($agent, 'v1.0.0', UPGRADE_OLD_SHA); // keep it online
    app()->call([new SweepFleet, 'handle']);

    expect(AgentUpgrade::query()->find($upgrade->id))->status->toBe(AgentUpgradeStatus::Failed)
        ->error->toContain('did not come back with v1.1.0');
});

it('rolls out to every outdated online agent one at a time and stops at the first failure', function () {
    $a = upgrade_agent($this->organization->id);
    $b = upgrade_agent($this->organization->id);
    $c = upgrade_agent($this->organization->id);
    upgrade_agent($this->organization->id, ['agent_version' => 'v1.1.0', 'agent_sha256' => $this->shippedSha]); // current: skipped
    [, $other] = memberOf();
    upgrade_agent($other->id); // other organization: untouched

    $queued = app(AgentUpgrades::class)->upgradeOrganization($this->organization->id);
    $status = fn (array $agent) => AgentUpgrade::query()->where('server_id', $agent['serverId'])->first()->status->value;

    expect($queued)->toHaveCount(3)
        ->and(AgentUpgrade::query()->count())->toBe(3)
        ->and(collect([$a, $b, $c])->map($status)->all())->toBe(['running', 'queued', 'queued'])
        ->and(upgrade_command($b['serverId']))->toBeNull();

    upgrade_finish($a, upgrade_command($a['serverId']));
    upgrade_report($a, 'v1.1.0', $this->shippedSha);
    expect(collect([$a, $b, $c])->map($status)->all())->toBe(['succeeded', 'running', 'queued']);

    upgrade_finish($b, upgrade_command($b['serverId']), exit: 1, error: 'the downloaded kiln-agent does not run on this host');
    expect(collect([$a, $b, $c])->map($status)->all())->toBe(['succeeded', 'failed', 'cancelled'])
        ->and(AgentUpgrade::query()->where('server_id', $c['serverId'])->value('error'))->toContain('Rollout stopped')
        ->and(upgrade_command($c['serverId']))->toBeNull();
});

it('refuses offline agents and missing builds', function () {
    $agent = upgrade_agent($this->organization->id, ['arch' => 'arm64']);

    expect(fn () => app(AgentUpgrades::class)->upgrade($agent['serverId']))->toThrow(AgentUpgradeUnavailable::class, 'no verifiable kiln-agent build for arm64');
});

it('counts outdated agents for kiln-ctl', function () {
    upgrade_agent($this->organization->id);
    upgrade_agent($this->organization->id, ['agent_version' => 'v1.1.0', 'agent_sha256' => $this->shippedSha]);

    $this->artisan('kiln:agents --outdated --count')->expectsOutput('1')->assertSuccessful();
    $this->artisan('kiln:agents')->expectsOutputToContain('kiln-agent linux-amd64: v1.1.0')->assertSuccessful();
});
