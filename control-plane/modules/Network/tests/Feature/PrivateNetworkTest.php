<?php

use Illuminate\Support\Facades\Event;
use Falak\Identity\Contracts\Role;
use Falak\Network\Application\ConvergePrivateNetwork;
use Falak\Network\Contracts\PrivateNetwork as PrivateNetworkContract;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Enums\KeyStatus;
use Falak\Network\Domain\Models\PrivateNetwork;
use Falak\Network\Domain\Models\PrivateNetworkMember;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Network\Infrastructure\WireGuardKeys;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Events\ServerDeleted;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->a = network_server($this->organization, ['name' => 'web-1', 'ipv4' => '203.0.113.1']);
    $this->b = network_server($this->organization, ['name' => 'web-2', 'ipv4' => '203.0.113.2']);
    $this->c = network_server($this->organization, ['name' => 'db-1', 'ipv4' => '203.0.113.3'], ServerType::Database);

    $this->post('/network/private-networks', ['name' => 'backplane'])->assertSessionHasNoErrors();
    $this->network = PrivateNetwork::query()->firstOrFail();
});

/**
 * Join a server and let the agent install its key.
 */
function network_join(object $test, string $serverId): PrivateNetworkMember
{
    $test->post("/network/private-networks/{$test->network->id}/members", ['server_id' => $serverId])->assertSessionHasNoErrors();
    $write = $test->agents->last('system.write_file', $serverId);
    $test->agents->succeed($write['handle'], ['changed' => true]);

    return PrivateNetworkMember::query()->where('server_id', $serverId)->firstOrFail();
}

/**
 * Settle every in-flight wireguard apply successfully.
 */
function network_settle(object $test): void
{
    foreach (PrivateNetworkMember::query()->where('status', ApplyStatus::Applying)->get() as $member) {
        $test->agents->succeed($member->command_id, ['changed' => true, 'public_key' => $member->public_key]);
    }
}

it('creates networks with a default range, unique interface and validation', function () {
    expect($this->network->cidr)->toBe('10.90.0.0/24')
        ->and($this->network->listen_port)->toBe(51820)
        ->and($this->network->interface)->toMatch('/^wg-[a-z0-9]{8}$/');

    $this->post('/network/private-networks', ['name' => 'backplane'])->assertSessionHasErrors('name');
    $this->post('/network/private-networks', ['name' => 'v6', 'cidr' => 'fd00::/64'])->assertSessionHasErrors('cidr');
    $this->post('/network/private-networks', ['name' => 'huge', 'cidr' => '10.0.0.0/8'])->assertSessionHasErrors('cidr');
    $this->post('/network/private-networks', ['name' => 'tiny', 'cidr' => '10.0.0.0/30'])->assertSessionHasErrors('cidr');
    $this->post('/network/private-networks', ['name' => 'overlap', 'cidr' => '10.90.0.128/25'])->assertSessionHasErrors('cidr');
    $this->post('/network/private-networks', ['name' => 'other', 'cidr' => '10.91.0.9/24', 'listen_port' => 51821])->assertSessionHasNoErrors();

    expect(PrivateNetwork::query()->where('name', 'other')->value('cidr'))->toBe('10.91.0.0/24');
});

it('generates a key pair per member and installs the private key on the host only', function () {
    $this->post("/network/private-networks/{$this->network->id}/members", ['server_id' => $this->a->id])->assertSessionHasNoErrors();

    $member = PrivateNetworkMember::query()->firstOrFail();
    $write = $this->agents->last('system.write_file', $this->a->id);

    expect($member->address)->toBe('10.90.0.1')
        ->and($member->key_status)->toBe(KeyStatus::Pending)
        ->and(WireGuardKeys::isPublicKey($member->public_key))->toBeTrue()
        ->and((new WireGuardKeys)->publicKeyFor(trim($write['payload']['content'])))->toBe($member->public_key)
        ->and($write['payload'])->toMatchArray(['path' => "/etc/falak/wireguard/{$this->network->interface}.key", 'mode' => '0600', 'owner' => 'root'])
        ->and(network_schema_errors('system.write_file', $write['payload']))->toBe([])
        ->and($member->toArray())->not->toHaveKey('private_key');

    $this->agents->assertNothingDispatched('net.wireguard.apply');

    $this->agents->succeed($write['handle'], ['changed' => true]);
    $member->refresh();

    expect($member->key_status)->toBe(KeyStatus::Installed)
        ->and($member->private_key)->toBeNull()
        ->and($member->status)->toBe(ApplyStatus::Applying);

    $apply = $this->agents->last('net.wireguard.apply', $this->a->id);
    expect(network_schema_errors('net.wireguard.apply', $apply['payload']))->toBe([])
        // The first apply may install wireguard-tools.
        ->and($apply['timeout'])->toBe(900)
        ->and($apply['payload'])->toEqual([
            'address' => '10.90.0.1/24',
            'interface' => $this->network->interface,
            'listen_port' => 51820,
            'peers' => [],
            'state' => 'present',
        ])
        ->and($apply['handle']->idempotencyKey)->toBe("net.wireguard:{$member->id}:2");
});

it('builds a full mesh and re-applies every peer when servers join and leave', function () {
    Event::fake([PrivateNetworkChanged::class]);
    $ma = network_join($this, $this->a->id);
    network_settle($this);
    $mb = network_join($this, $this->b->id);
    $mc = network_join($this, $this->c->id);
    network_settle($this);

    expect([$ma->address, $mb->address, $mc->address])->toBe(['10.90.0.1', '10.90.0.2', '10.90.0.3']);

    foreach ([[$this->a, $ma], [$this->b, $mb], [$this->c, $mc]] as [$server, $member]) {
        $payload = $this->agents->last('net.wireguard.apply', $server->id)['payload'];
        $others = collect([$ma, $mb, $mc])->reject(fn ($m) => $m->id === $member->id);

        expect(network_schema_errors('net.wireguard.apply', $payload))->toBe([])
            ->and($payload['address'])->toBe("{$member->address}/24")
            ->and(array_column($payload['peers'], 'public_key'))->toBe($others->pluck('public_key')->values()->all())
            ->and(array_column($payload['peers'], 'allowed_ips'))->toBe($others->map(fn ($m) => ["{$m->address}/32"])->values()->all())
            ->and($payload['peers'][0]['persistent_keepalive'])->toBe(25);
    }

    expect($this->agents->last('net.wireguard.apply', $this->a->id)['payload']['peers'][0]['endpoint'])->toBe('203.0.113.2:51820');

    // Firewalls of members accept WireGuard from peers and everything on the interface.
    $firewall = $this->agents->last('net.firewall.apply', $this->a->id)['payload'];
    expect(network_schema_errors('net.firewall.apply', $firewall))->toBe([])
        ->and($firewall['rules'][0])->toMatchArray(['action' => 'accept', 'protocol' => 'udp', 'ports' => ['51820'], 'sources' => ['203.0.113.2', '203.0.113.3']])
        ->and($firewall['rules'][1])->toMatchArray(['action' => 'accept', 'protocol' => 'any', 'interface' => $this->network->interface]);

    // Remove b: it is torn down, a and c drop it as a peer.
    $before = count($this->agents->dispatched('net.wireguard.apply'));
    $this->delete("/network/private-networks/{$this->network->id}/members/{$mb->id}")->assertSessionHasNoErrors();

    $new = array_slice($this->agents->dispatched('net.wireguard.apply'), $before);
    $byServer = collect($new)->keyBy(fn ($c) => $c['handle']->serverId);

    expect($byServer)->toHaveCount(3)
        ->and($byServer[$this->b->id]['payload'])->toMatchArray(['state' => 'absent', 'address' => '10.90.0.2/24'])
        ->and(network_schema_errors('net.wireguard.apply', $byServer[$this->b->id]['payload']))->toBe([])
        ->and(array_column($byServer[$this->a->id]['payload']['peers'], 'public_key'))->toBe([$mc->public_key])
        ->and(array_column($byServer[$this->c->id]['payload']['peers'], 'public_key'))->toBe([$ma->public_key])
        ->and($this->agents->last('net.firewall.apply', $this->a->id)['payload']['rules'][0]['sources'])->toBe(['203.0.113.3'])
        ->and(collect($this->agents->last('net.firewall.apply', $this->b->id)['payload']['rules'])->pluck('id')->filter(fn ($id) => str_starts_with($id, 'wg-')))->toBeEmpty();

    // The freed address is reused.
    $mb2 = network_join($this, $this->b->id);
    expect($mb2->address)->toBe('10.90.0.2');

    Event::assertDispatched(PrivateNetworkChanged::class, fn ($e) => $e->change === PrivateNetworkChanged::MEMBER_ADDED && $e->serverIds === [$this->c->id]);
    Event::assertDispatched(PrivateNetworkChanged::class, fn ($e) => $e->change === PrivateNetworkChanged::MEMBER_REMOVED && $e->serverIds === [$this->b->id]);
    Event::assertDispatched(PrivateNetworkChanged::class, fn ($e) => $e->change === PrivateNetworkChanged::APPLIED);
});

it('does not re-dispatch converged members', function () {
    network_join($this, $this->a->id);
    network_join($this, $this->b->id);
    network_settle($this);
    $count = count($this->agents->dispatched('net.wireguard.apply'));

    app(ConvergePrivateNetwork::class)($this->network->refresh());
    expect($this->agents->dispatched('net.wireguard.apply'))->toHaveCount($count);

    $this->post("/network/private-networks/{$this->network->id}/apply")->assertSessionHasNoErrors();
    expect($this->agents->dispatched('net.wireguard.apply'))->toHaveCount($count + 2);
});

it('adopts the key the host reports and re-applies the peers', function () {
    $ma = network_join($this, $this->a->id);
    $mb = network_join($this, $this->b->id);
    network_settle($this);

    $hostKey = (new WireGuardKeys)->generate()['public'];
    $this->post("/network/private-networks/{$this->network->id}/apply");
    $this->agents->succeed($ma->refresh()->command_id, ['changed' => true, 'public_key' => $hostKey]);

    expect($ma->refresh()->public_key)->toBe($hostKey)
        ->and($this->agents->last('net.wireguard.apply', $this->b->id)['payload']['peers'][0]['public_key'])->toBe($hostKey)
        ->and($mb->refresh()->status)->toBe(ApplyStatus::Applying);
});

it('records failures and retries key delivery on re-apply', function () {
    $this->post("/network/private-networks/{$this->network->id}/members", ['server_id' => $this->a->id]);
    $write = $this->agents->last('system.write_file', $this->a->id);
    $this->agents->fail($write['handle'], 'read-only file system');

    $member = PrivateNetworkMember::query()->firstOrFail();
    expect($member->key_status)->toBe(KeyStatus::Failed)
        ->and($member->status)->toBe(ApplyStatus::Failed)
        ->and($member->error)->toContain('read-only file system')
        ->and($member->private_key)->not->toBeNull();

    $this->post("/network/private-networks/{$this->network->id}/apply");
    $retry = $this->agents->last('system.write_file', $this->a->id);
    expect($retry['handle']->id)->not->toBe($write['handle']->id)
        ->and($retry['handle']->idempotencyKey)->not->toBe($write['handle']->idempotencyKey);

    $this->agents->succeed($retry['handle']);
    $this->agents->fail($member->refresh()->command_id, 'wg-quick: failed');
    expect($member->refresh()->status)->toBe(ApplyStatus::Failed)->and($member->error)->toBe('wg-quick: failed');
});

it('validates members', function () {
    $inactive = network_server($this->organization, ['name' => 'new', 'status' => ServerStatus::Provisioning]);
    [, $other] = memberOf();
    $foreign = network_server($other, ['name' => 'foreign']);

    network_join($this, $this->a->id);
    $this->post("/network/private-networks/{$this->network->id}/members", ['server_id' => $this->a->id])->assertSessionHasErrors('server_id');
    $this->post("/network/private-networks/{$this->network->id}/members", ['server_id' => $inactive->id])->assertSessionHasErrors('server_id');
    $this->post("/network/private-networks/{$this->network->id}/members", ['server_id' => $foreign->id])->assertNotFound();

    // Same listen port in two networks on one server clashes.
    $this->post('/network/private-networks', ['name' => 'second', 'cidr' => '10.91.0.0/24']);
    $second = PrivateNetwork::query()->where('name', 'second')->firstOrFail();
    $this->post("/network/private-networks/{$second->id}/members", ['server_id' => $this->a->id])->assertSessionHasErrors('server_id');
});

it('refuses to join servers once the range is exhausted', function () {
    $this->post('/network/private-networks', ['name' => 'small', 'cidr' => '10.92.0.0/29', 'listen_port' => 51900]);
    $small = PrivateNetwork::query()->where('name', 'small')->firstOrFail();

    foreach (range(1, 6) as $i) {
        $server = network_server($this->organization, ['name' => "n{$i}"]);
        $this->post("/network/private-networks/{$small->id}/members", ['server_id' => $server->id])->assertSessionHasNoErrors();
    }

    $last = network_server($this->organization, ['name' => 'n7']);
    $this->post("/network/private-networks/{$small->id}/members", ['server_id' => $last->id])->assertSessionHasErrors(['server_id' => 'The network 10.92.0.0/29 has no free addresses left.']);
    expect($small->members()->pluck('address')->all())->toBe(['10.92.0.1', '10.92.0.2', '10.92.0.3', '10.92.0.4', '10.92.0.5', '10.92.0.6']);
});

it('tears the whole network down on deletion', function () {
    Event::fake([PrivateNetworkChanged::class]);
    network_join($this, $this->a->id);
    network_join($this, $this->b->id);
    network_settle($this);

    $this->delete("/network/private-networks/{$this->network->id}", ['name' => 'wrong'])->assertSessionHasErrors('name');
    $this->delete("/network/private-networks/{$this->network->id}", ['name' => 'backplane'])->assertRedirect('/network');

    foreach ([$this->a, $this->b] as $server) {
        expect($this->agents->last('net.wireguard.apply', $server->id)['payload']['state'])->toBe('absent')
            ->and(collect($this->agents->last('net.firewall.apply', $server->id)['payload']['rules'])->filter(fn ($r) => str_starts_with($r['id'], 'wg-')))->toBeEmpty();
    }

    expect(PrivateNetwork::query()->count())->toBe(0)->and(PrivateNetworkMember::query()->count())->toBe(0);
    Event::assertDispatched(PrivateNetworkChanged::class, fn ($e) => $e->change === PrivateNetworkChanged::DELETED && $e->serverIds === [$this->a->id, $this->b->id]);
});

it('removes deleted servers from their networks', function () {
    network_join($this, $this->a->id);
    $mb = network_join($this, $this->b->id);
    network_settle($this);

    ServerDeleted::dispatch($this->a->id, $this->organization->id, 'web', 'web-1');

    expect(PrivateNetworkMember::query()->pluck('server_id')->all())->toBe([$this->b->id])
        ->and($this->agents->last('net.wireguard.apply', $this->b->id)['payload']['peers'])->toBe([])
        ->and($this->agents->dispatched('net.wireguard.apply', $this->a->id))->each(fn ($c) => $c->payload->state->toBe('present'));
});

it('exposes private addresses through the PrivateNetwork contract', function () {
    $contract = app(PrivateNetworkContract::class);
    network_join($this, $this->a->id);

    $this->post('/network/private-networks', ['name' => 'second', 'cidr' => '10.91.0.0/24', 'listen_port' => 51821]);
    $second = PrivateNetwork::query()->where('name', 'second')->firstOrFail();
    $this->post("/network/private-networks/{$second->id}/members", ['server_id' => $this->b->id]);
    $this->post("/network/private-networks/{$second->id}/members", ['server_id' => $this->a->id]);

    expect($contract->addressOf($this->a->id))->toBe('10.90.0.1')
        ->and($contract->addressOf($this->a->id, $second->id))->toBe('10.91.0.2')
        ->and($contract->addressOf($this->c->id))->toBeNull()
        ->and($contract->networksOf($this->a->id))->toHaveCount(2)
        ->and($contract->networksOf($this->a->id)[0]->name)->toBe('backplane')
        ->and($contract->networksOf($this->a->id)[0]->cidr)->toBe('10.90.0.0/24')
        ->and($contract->networksOf($this->a->id)[1]->interface)->toBe($second->interface);
});

it('lists load balancers with their private addresses', function () {
    $lb = network_server($this->organization, ['name' => 'lb-1', 'ipv4' => '198.51.100.7'], ServerType::LoadBalancer);
    network_join($this, $lb->id);

    $this->get('/network')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Network/Index', false)
        ->has('loadBalancers', 1)
        ->where('loadBalancers.0.name', 'lb-1')
        ->where('loadBalancers.0.ipv4', '198.51.100.7')
        ->where('loadBalancers.0.private_addresses.0.address', '10.90.0.1')
        ->has('networks', 1)
        ->where('networks.0.members_count', 1));
});

it('authorizes private network access', function () {
    $ma = network_join($this, $this->a->id);

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);
    $this->get("/network/private-networks/{$this->network->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('Network/PrivateNetwork', false)
        ->has('members', 1)
        ->where('members.0.address', '10.90.0.1')
        ->missing('members.0.private_key')
        ->where('can.manage', false));
    $this->post('/network/private-networks', ['name' => 'x'])->assertForbidden();
    $this->post("/network/private-networks/{$this->network->id}/members", ['server_id' => $this->b->id])->assertForbidden();
    $this->delete("/network/private-networks/{$this->network->id}/members/{$ma->id}")->assertForbidden();
    $this->delete("/network/private-networks/{$this->network->id}", ['name' => 'backplane'])->assertForbidden();

    [$outsider] = memberOf(null, Role::Owner);
    $this->actingAs($outsider);
    $this->get("/network/private-networks/{$this->network->id}")->assertNotFound();
    $this->post("/network/private-networks/{$this->network->id}/apply")->assertNotFound();
    $this->delete("/network/private-networks/{$this->network->id}/members/{$ma->id}")->assertNotFound();
});

it('seeds default rules before compiling the firewall of a server nobody configured yet', function () {
    // web-1 predates the Network module: no firewall state, no rules.
    network_join($this, $this->a->id);

    $firewall = $this->agents->last('net.firewall.apply', $this->a->id)['payload'];
    $ports = collect($firewall['rules'])->pluck('ports')->flatten()->all();

    expect($firewall['input_policy'])->toBe('drop')
        ->and($ports)->toContain('22', '80', '443')
        ->and(network_schema_errors('net.firewall.apply', $firewall))->toBe([]);
});
