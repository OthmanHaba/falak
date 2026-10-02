<?php

use Kiln\Databases\Application\Actions\EnableContainerAccess;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Fleet\Application\PayloadCompatibility;
use Kiln\Fleet\Events\AgentVersionChanged;
use Kiln\Identity\Contracts\Role;
use Kiln\Network\Infrastructure\FirewallCompiler;
use Kiln\Servers\Contracts\ServerType;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->pg = databases_engine($this->organization, 'postgresql');
});

it('sends the Docker ranges with users of app-server engines, not of dedicated database servers', function () {
    databases_active_db($this->pg, 'shop');
    $this->post("/databases/servers/{$this->pg->id}/users", ['username' => 'shop', 'grants' => []])->assertSessionHasNoErrors();
    $apply = $this->agents->last('db.user.apply');

    expect($apply['payload']['containers'])->toBe(['172.16.0.0/12', '192.168.0.0/16'])
        ->and(databases_schema_errors($apply))->toBe([]);

    $dedicated = databases_engine($this->organization, 'mysql', ServerType::Database);
    $this->post("/databases/servers/{$dedicated->id}/users", ['username' => 'web', 'grants' => []])->assertSessionHasNoErrors();
    expect($this->agents->last('db.user.apply')['payload'])->not->toHaveKey('containers');

    // A MySQL user pinned to localhost stays local.
    $mysql = databases_engine($this->organization, 'mysql');
    $this->post("/databases/servers/{$mysql->id}/users", ['username' => 'cron', 'host' => 'localhost', 'grants' => []])->assertSessionHasNoErrors();
    expect($this->agents->last('db.user.apply')['payload'])->not->toHaveKey('containers');
    $this->post("/databases/servers/{$mysql->id}/users", ['username' => 'web', 'grants' => []])->assertSessionHasNoErrors();
    expect($this->agents->last('db.user.apply')['payload'])->toHaveKey('containers');

    // Agents without db.containers never see the field.
    $old = PayloadCompatibility::adapt('db.user.apply', (object) $apply['payload'], ['fn.v3']);
    expect((array) $old)->not->toHaveKey('containers');
});

it('turns container access on when the agent learns db.containers: users re-applied, ports open on the Docker bridges', function () {
    databases_active_db($this->pg, 'shop');
    $this->post("/databases/servers/{$this->pg->id}/users", ['username' => 'shop', 'grants' => []])->assertSessionHasNoErrors();
    $user = DatabaseUser::query()->where('username', 'shop')->firstOrFail();
    $before = count($this->agents->dispatched('db.user.apply'));

    // An older agent: nothing happens.
    event(new AgentVersionChanged('agent', $this->organization->id, $this->pg->server_id, '0.4.3', '0.4.4', ['fn.v3']));
    expect($this->pg->refresh()->container_access)->toBeFalse()
        ->and(app(FirewallCompiler::class)->compile($this->pg->server_id))->not->toHaveKey('container_ports');

    event(new AgentVersionChanged('agent', $this->organization->id, $this->pg->server_id, '0.4.4', '0.4.5', ['fn.v3', 'db.containers']));

    expect($this->pg->refresh()->container_access)->toBeTrue()
        ->and(count($this->agents->dispatched('db.user.apply')))->toBe($before + 1)
        ->and($this->agents->last('db.user.apply')['handle']->idempotencyKey)->toBe("db.user.apply:{$user->id}:".$user->refresh()->revision);

    $firewall = $this->agents->last('net.firewall.apply');
    expect($firewall['payload']['container_ports'])->toBe([['id' => 'postgresql', 'protocol' => 'tcp', 'ports' => ['5432'], 'sources' => ['172.16.0.0/12', '192.168.0.0/16'], 'comment' => 'PostgreSQL for containers']])
        ->and(databases_schema_errors($firewall))->toBe([]);

    // Idempotent: a second upgrade event does not re-apply anything.
    expect(app(EnableContainerAccess::class)($this->pg->server_id, ['db.containers']))->toBeFalse();
});

it('never opens dedicated database servers to containers (they are reached over the network)', function () {
    $dedicated = databases_engine($this->organization, 'postgresql', ServerType::Database);

    expect(app(EnableContainerAccess::class)($dedicated->server_id, ['db.containers']))->toBeFalse()
        ->and(app(FirewallCompiler::class)->compile($dedicated->server_id))->not->toHaveKey('container_ports');
});
