<?php

use Illuminate\Support\Str;
use Kiln\Databases\Application\Actions\EnableContainerAccess;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Contracts\Data\DatabaseConsumer;
use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Databases\Contracts\DatabaseProvisioner;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Infrastructure\DatabaseContainerPorts;
use Kiln\Fleet\Application\PayloadCompatibility;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Events\AgentVersionChanged;
use Kiln\Identity\Contracts\Role;
use Kiln\Network\Domain\Enums\ApplyStatus;
use Kiln\Network\Domain\Models\PrivateNetwork;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Network\Events\PrivateNetworkChanged;
use Kiln\Network\Infrastructure\FirewallCompiler;
use Kiln\Projects\Application\Actions\LinkService;
use Kiln\Projects\Application\Actions\UnlinkService;
use Kiln\Projects\Contracts\ServiceKind;
use Kiln\Projects\Domain\Models\Service;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\ServerProvisioned;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';
require_once __DIR__.'/../../../Network/tests/Support/helpers.php';

/*
 * Redis / Valkey network access (v0.7.1, phase 2; feature db.redis.network): containers on the instance's server
 * through the Docker bridge, other servers of the environment over a private network — never a public address.
 */

const KV_NET = ['db.redis', 'db.containers', 'db.redis.network'];

function kvnet_server(object $test, string $name, array $features = KV_NET, array $attributes = []): Server
{
    $server = databases_server($test->organization, 'postgresql', ServerType::App, [
        'name' => $name,
        'status' => ServerStatus::Active,
        'provider' => 'hetzner',
        'stack' => ['database' => 'postgresql', 'cache' => 'redis'],
        'ipv4' => '203.0.113.'.random_int(30, 250),
        'private_ipv4' => null,
        ...$attributes,
    ]);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $test->organization->id, 'facts' => ['features' => $features, 'memory_bytes' => 2 * 1024 ** 3]]);
    app(EngineInventory::class)->sync($server->id);
    app(EnableContainerAccess::class)($server->id);

    return $server;
}

/** An active instance on $server, placed in the default production environment. */
function kvnet_instance(object $test, Server $server, string $name = 'cache', bool $place = true): Database
{
    $data = app(DatabaseProvisioner::class)->create($test->organization->id, $server->id, 'redis', $name);
    $apply = $test->agents->last('db.redis.apply', $server->id);
    $test->agents->succeed($apply['handle'], kvnet_result($apply['payload']));

    if ($place) {
        app(LinkService::class)(projects_default_env($test->organization), ServiceKind::Database, $data->id, $name);
    }

    return Database::query()->findOrFail($data->id);
}

/** What an agent reports for a payload: everything bound, docker0 = 172.17.0.1 when asked. */
function kvnet_result(array $payload, array $skipped = []): array
{
    $bind = array_values(array_diff($payload['bind'], $skipped));

    if ($payload['containers'] ?? false) {
        $bind[] = '172.17.0.1';
    }

    return array_filter(['changed' => true, 'restarted' => true, 'port' => $payload['port'], 'bind' => $bind, 'container_host' => ($payload['containers'] ?? false) ? '172.17.0.1' : null, 'skipped' => $skipped ?: null], fn ($v) => $v !== null);
}

function kvnet_wireguard(object $test, array $members): PrivateNetwork
{
    $network = PrivateNetwork::query()->create(['organization_id' => $test->organization->id, 'name' => 'mesh', 'cidr' => '10.90.0.0/24', 'interface' => 'wg-'.Str::lower(Str::random(8)), 'listen_port' => 51820]);

    foreach ($members as $address => $server) {
        PrivateNetworkMember::query()->create(['organization_id' => $test->organization->id, 'network_id' => $network->id, 'server_id' => $server->id, 'address' => $address, 'public_key' => base64_encode(random_bytes(32)), 'private_key' => base64_encode(random_bytes(32)), 'key_status' => 'installed', 'status' => ApplyStatus::Applied]);
    }

    return $network;
}

function kvnet_consumer(Server|array $servers, bool $containerized = false, string $name = 'shop'): DatabaseConsumer
{
    return new DatabaseConsumer($name, array_map(fn (Server $s) => $s->id, is_array($servers) ? $servers : [$servers]), $containerized);
}

function kvnet_ports(Server $server): array
{
    return collect(app(DatabaseContainerPorts::class)->for($server->id))->keyBy('id')->all();
}

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->connections = app(DatabaseConnections::class);
});

it('keeps instances on 127.0.0.1 for agents without db.redis.network, and strips the new fields for them', function () {
    $server = kvnet_server($this, 'app-old', ['db.redis', 'db.containers']);
    $instance = kvnet_instance($this, $server);

    expect($this->agents->last('db.redis.apply')['payload'])->toMatchArray(['bind' => ['127.0.0.1']])->not->toHaveKey('containers')
        ->and(DatabaseServer::query()->where('server_id', $server->id)->where('engine', 'redis')->value('container_access'))->toBeFalse()
        ->and(kvnet_ports($server))->not->toHaveKey('redis-cache');

    $other = kvnet_server($this, 'app-b', ['db.redis', 'db.containers']);
    expect($this->connections->unreachable($instance->id, kvnet_consumer($server, true)))->toContain('update the agent on app-old')
        ->and($this->connections->unreachable($instance->id, kvnet_consumer($other)))->toContain('runs on app-b')->toContain('update the agent on app-old')
        ->and($this->connections->unreachable($instance->id, kvnet_consumer($server)))->toBeNull()
        ->and($this->connections->variables($instance->id, kvnet_consumer($other))['REDIS_HOST'])->toBe('127.0.0.1');

    $redis = PayloadCompatibility::adapt('db.redis.apply', (object) ['name' => 'cache', 'bind' => ['127.0.0.1'], 'containers' => true], ['db.redis']);
    $firewall = PayloadCompatibility::adapt('net.firewall.apply', json_decode('{"container_ports":[{"id":"redis-cache","ports":["6380"],"sources":["172.16.0.0/12"],"peers":["10.90.0.2"]}]}'), ['db.containers']);
    $kept = PayloadCompatibility::adapt('db.redis.apply', (object) ['containers' => true], ['db.redis', 'db.redis.network']);
    expect((array) $redis)->not->toHaveKey('containers')
        ->and((array) $firewall->container_ports[0])->not->toHaveKey('peers')->toHaveKey('sources')
        ->and($kept->containers)->toBeTrue();
});

it('lets containers on the server reach an instance through the Docker bridge', function () {
    $server = kvnet_server($this, 'app-a');
    $data = app(DatabaseProvisioner::class)->create($this->organization->id, $server->id, 'redis', 'cache');
    $apply = $this->agents->last('db.redis.apply');

    expect($apply['payload'])->toMatchArray(['bind' => ['127.0.0.1'], 'containers' => true])
        ->and(databases_schema_errors($apply))->toBe([])
        // Not listening on the bridge yet: containers wait for the agent's answer.
        ->and($this->connections->unreachable($data->id, kvnet_consumer($server, true)))->toContain('does not listen on the Docker bridge (docker0) yet');

    $this->agents->succeed($apply['handle'], kvnet_result($apply['payload']));
    $instance = Database::query()->findOrFail($data->id);

    expect($instance->network)->toMatchArray(['bind' => ['127.0.0.1', '172.17.0.1'], 'container_host' => '172.17.0.1', 'skipped' => []])
        ->and($this->connections->unreachable($data->id, kvnet_consumer($server, true)))->toBeNull();

    $variables = $this->connections->variables($data->id, kvnet_consumer($server, true));
    expect($variables['REDIS_HOST'])->toBe('172.17.0.1')
        ->and($variables['REDIS_URL'])->toBe('redis://default:'.$variables['REDIS_PASSWORD'].'@172.17.0.1:6380')
        ->and($this->connections->variables($data->id, kvnet_consumer($server))['REDIS_HOST'])->toBe('127.0.0.1');

    // The firewall: the instance's own port, from the Docker ranges on the bridges only; no peers.
    expect(kvnet_ports($server)['redis-cache'])->toBe(['id' => 'redis-cache', 'protocol' => 'tcp', 'ports' => ['6380'], 'sources' => ['172.16.0.0/12', '192.168.0.0/16'], 'comment' => 'Redis cache']);
    $firewall = $this->agents->last('net.firewall.apply', $server->id);
    expect(collect($firewall['payload']['container_ports'] ?? [])->pluck('id')->all())->toContain('postgresql');
    expect(network_schema_errors('net.firewall.apply', app(FirewallCompiler::class)->compile($server->id)))->toBe([]);
});

it('re-applies existing instances when the agent learns db.redis.network', function () {
    $server = kvnet_server($this, 'app-a', ['db.redis', 'db.containers']);
    $instance = kvnet_instance($this, $server);
    $before = count($this->agents->dispatched('db.redis.apply'));

    event(new AgentVersionChanged('agent', $this->organization->id, $server->id, '0.7.0', '0.7.1', KV_NET));

    expect(DatabaseServer::query()->where('server_id', $server->id)->where('engine', 'redis')->value('container_access'))->toBeTrue()
        ->and($this->agents->dispatched('db.redis.apply'))->toHaveCount($before + 1)
        ->and($this->agents->last('db.redis.apply')['payload'])->toMatchArray(['name' => 'cache', 'bind' => ['127.0.0.1'], 'containers' => true])
        ->and(collect($this->agents->last('net.firewall.apply', $server->id)['payload']['container_ports'])->pluck('id')->all())->toContain('redis-cache');

    // Learning it again changes nothing.
    event(new AgentVersionChanged('agent', $this->organization->id, $server->id, '0.7.1', '0.7.1', KV_NET));
    expect($this->agents->dispatched('db.redis.apply'))->toHaveCount($before + 1);
});

it('reaches an instance from another server over a WireGuard network: bind, firewall peers, host', function () {
    $a = kvnet_server($this, 'app-a', attributes: ['private_ipv4' => '10.0.1.5']);
    $b = kvnet_server($this, 'app-b', attributes: ['private_ipv4' => '10.0.1.6']);
    kvnet_wireguard($this, ['10.90.0.1' => $a, '10.90.0.2' => $b]);
    $instance = kvnet_instance($this, $a);
    $applies = count($this->agents->dispatched('db.redis.apply'));

    // A site of the environment on app-b: the instance listens on app-a's WireGuard address (preferred over the
    // provider's), the firewall lets app-b's in.
    projects_site($this->organization, 'shop', [], projects_default_env($this->organization), [$b]);

    $apply = $this->agents->last('db.redis.apply');
    expect($this->agents->dispatched('db.redis.apply'))->toHaveCount($applies + 1)
        ->and($apply['payload'])->toMatchArray(['bind' => ['127.0.0.1', '10.90.0.1'], 'containers' => true])
        ->and(kvnet_ports($a)['redis-cache']['peers'])->toBe(['10.90.0.2'])
        ->and(network_schema_errors('net.firewall.apply', app(FirewallCompiler::class)->compile($a->id)))->toBe([])
        // Until the agent listens there, the reference waits.
        ->and($this->connections->unreachable($instance->id, kvnet_consumer($b)))->toContain('does not listen on 10.90.0.1 (private network mesh) yet');

    $this->agents->succeed($apply['handle'], kvnet_result($apply['payload']));

    foreach ([kvnet_consumer($b), kvnet_consumer($b, true), kvnet_consumer([$a, $b])] as $consumer) {
        expect($this->connections->unreachable($instance->id, $consumer))->toBeNull()
            ->and($this->connections->variables($instance->id, $consumer)['REDIS_HOST'])->toBe('10.90.0.1');
    }
    expect($this->connections->variables($instance->id, kvnet_consumer($b))['REDIS_URL'])->toEndWith('@10.90.0.1:6380');

    // An unrelated change re-applies nothing (same bind), the firewall converges idempotently.
    event(new PrivateNetworkChanged('x', $this->organization->id, PrivateNetworkChanged::APPLIED, [$a->id]));
    expect($this->agents->dispatched('db.redis.apply'))->toHaveCount($applies + 1);

    // The site leaves the environment: back to localhost and containers, no peers.
    $service = Service::query()->where('kind', ServiceKind::Site)->where('name', 'shop')->firstOrFail();
    app(UnlinkService::class)(ServiceKind::Site, $service->ref_id);
    expect($this->agents->last('db.redis.apply')['payload']['bind'])->toBe(['127.0.0.1'])
        ->and(kvnet_ports($a)['redis-cache'])->not->toHaveKey('peers');
});

it('falls back to the provider private network, and never resolves to a public address', function () {
    $a = kvnet_server($this, 'app-a', attributes: ['private_ipv4' => '10.0.1.5']);
    $b = kvnet_server($this, 'app-b', attributes: ['private_ipv4' => '10.0.1.6']);
    $public = kvnet_server($this, 'app-c');
    $elsewhere = kvnet_server($this, 'app-d', attributes: ['private_ipv4' => '10.0.1.7', 'provider' => 'digitalocean']);
    $instance = kvnet_instance($this, $a);

    projects_site($this->organization, 'shop', [], projects_default_env($this->organization), [$b]);
    projects_site($this->organization, 'blog', [], projects_default_env($this->organization), [$public]);
    projects_site($this->organization, 'wiki', [], projects_default_env($this->organization), [$elsewhere]);

    $apply = $this->agents->last('db.redis.apply');
    expect($apply['payload']['bind'])->toBe(['127.0.0.1', '10.0.1.5'])
        ->and(kvnet_ports($a)['redis-cache']['peers'])->toBe(['10.0.1.6']);
    $this->agents->succeed($apply['handle'], kvnet_result($apply['payload']));

    expect($this->connections->variables($instance->id, kvnet_consumer($b, true))['REDIS_HOST'])->toBe('10.0.1.5');

    foreach ([$public, $elsewhere] as $server) {
        $reason = $this->connections->unreachable($instance->id, kvnet_consumer($server, name: 'blog'));
        expect($reason)->toContain("blog runs on {$server->name}, which shares no private network with app-a")
            ->toContain('Add both servers to a private network (Network → Private networks)')
            ->and($this->connections->variables($instance->id, kvnet_consumer($server))['REDIS_HOST'])->not->toBe($server->ipv4)->toBe('127.0.0.1')
            ->and($this->connections->variables($instance->id, kvnet_consumer($server))['REDIS_HOST'])->not->toBe(Server::query()->find($a->id)->ipv4);
    }

    // A site on app-b and app-c: no network all of them share with app-a.
    expect($this->connections->unreachable($instance->id, kvnet_consumer([$b, $public])))->toContain('runs on app-c');
});

it('applies again when an address that was missing on the host appears, and when Docker arrives', function () {
    $a = kvnet_server($this, 'app-a');
    $b = kvnet_server($this, 'app-b');
    kvnet_wireguard($this, ['10.90.0.1' => $a, '10.90.0.2' => $b]);
    $instance = kvnet_instance($this, $a);
    projects_site($this->organization, 'shop', [], projects_default_env($this->organization), [$b]);

    // The WireGuard interface isn't up yet on app-a: the agent skips the address.
    $apply = $this->agents->last('db.redis.apply');
    $this->agents->succeed($apply['handle'], kvnet_result($apply['payload'], skipped: ['10.90.0.1']));
    expect($this->connections->unreachable($instance->id, kvnet_consumer($b)))->toContain('does not listen on 10.90.0.1');
    $applies = count($this->agents->dispatched('db.redis.apply'));

    event(new PrivateNetworkChanged('x', $this->organization->id, PrivateNetworkChanged::APPLIED, [$a->id]));
    expect($this->agents->dispatched('db.redis.apply'))->toHaveCount($applies + 1);
    $apply = $this->agents->last('db.redis.apply');
    // In flight: another event doesn't stack a second apply.
    event(new PrivateNetworkChanged('x', $this->organization->id, PrivateNetworkChanged::APPLIED, [$a->id]));
    expect($this->agents->dispatched('db.redis.apply'))->toHaveCount($applies + 1);

    $this->agents->succeed($apply['handle'], kvnet_result($apply['payload']));
    expect($this->connections->variables($instance->id, kvnet_consumer($b))['REDIS_HOST'])->toBe('10.90.0.1');

    // No docker0 when the instance was applied; the server's provisioning (Docker installed) applies it again.
    $c = kvnet_server($this, 'app-c');
    $other = kvnet_instance($this, $c, 'jobs');
    Database::query()->whereKey($other->id)->update(['network' => json_encode([...$other->network, 'container_host' => null, 'bind' => ['127.0.0.1']])]);
    $applies = count($this->agents->dispatched('db.redis.apply'));
    event(new ServerProvisioned($c->id, $this->organization->id, 'app', 'app-c'));
    expect($this->agents->dispatched('db.redis.apply'))->toHaveCount($applies + 1)
        ->and($this->agents->last('db.redis.apply')['payload']['name'])->toBe('jobs');
});

it('shows who can connect, and from where, on the instance panel', function () {
    $a = kvnet_server($this, 'app-a', attributes: ['private_ipv4' => '10.0.1.5']);
    $b = kvnet_server($this, 'app-b', attributes: ['private_ipv4' => '10.0.1.6']);
    $c = kvnet_server($this, 'app-c');
    $instance = kvnet_instance($this, $a);
    projects_site($this->organization, 'shop', [], projects_default_env($this->organization), [$b]);
    projects_site($this->organization, 'blog', [], projects_default_env($this->organization), [$c]);
    projects_site($this->organization, 'api', [], projects_default_env($this->organization), [$a], ['runtime' => 'docker']);
    $apply = $this->agents->last('db.redis.apply');
    $this->agents->succeed($apply['handle'], kvnet_result($apply['payload']));

    $connection = $this->getJson("/databases/databases/{$instance->id}")->assertOk()->json('data.connection');

    expect(collect($connection['hosts'])->map(fn ($h) => [$h['label'], $h['value']])->all())->toBe([
        ['Same server', '127.0.0.1'], ['Containers', '172.17.0.1'], ['Provider private IP', '10.0.1.5'],
    ]);
    $access = collect($connection['access'])->keyBy('name');
    expect($access['shop'])->toMatchArray(['host' => '10.0.1.5', 'reason' => null])
        ->and($access['api'])->toMatchArray(['host' => '172.17.0.1', 'reason' => null])
        ->and($access['blog']['host'])->toBeNull()
        ->and($access['blog']['reason'])->toContain('Add both servers to a private network');
});
