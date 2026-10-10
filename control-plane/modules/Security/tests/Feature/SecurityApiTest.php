<?php

use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->server = security_server($this->organization);
    security_backups([]);
});

it('reads the report, runs audits and applies fixes through the API', function () {
    $token = app(CreateApiToken::class)($this->user, $this->organization->id, 'cli', ['security.view', 'security.fix'])->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson("/api/v1/servers/{$this->server->id}/security")->assertNotFound();

    security_audit($this->agents, $this->server, [security_check('kernel.x', 'fail', 'low', 'kernel.sysctl', 'kernel')]);

    $this->withToken($token)->getJson("/api/v1/servers/{$this->server->id}/security")->assertOk()
        ->assertJsonPath('data.score', 98)
        ->assertJsonPath('data.production_ready', true)
        ->assertJsonFragment(['id' => 'kernel.x', 'fix_id' => 'kernel.sysctl']);

    $this->withToken($token)->postJson("/api/v1/servers/{$this->server->id}/security/fixes", ['fix_id' => 'kernel.sysctl'])->assertStatus(202)
        ->assertJsonPath('data.status', 'applying');
    $this->withToken($token)->postJson("/api/v1/servers/{$this->server->id}/security/audit")->assertStatus(202)->assertJsonPath('data.status', 'running');

    app('auth')->forgetGuards();
    $readOnly = app(CreateApiToken::class)($this->user, $this->organization->id, 'ro', ['security.view'])->plainTextToken;
    $this->withToken($readOnly)->postJson("/api/v1/servers/{$this->server->id}/security/fixes", ['fix_id' => 'kernel.sysctl'])->assertForbidden();

    app('auth')->forgetGuards();
    [$stranger, $other] = memberOf(null, Role::Admin);
    $theirs = app(CreateApiToken::class)($stranger, $other->id, 'x', ['*'])->plainTextToken;
    $this->withToken($theirs)->getJson("/api/v1/servers/{$this->server->id}/security")->assertNotFound();
});
