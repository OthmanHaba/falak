<?php

use Kiln\Fleet\Domain\Models\Command;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Models\SshKey;
use phpseclib3\Crypt\EC;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test']);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->publicKey = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH', ['comment' => 'me@laptop']);
});

function activeServerWithAgent(): array
{
    test()->post('/servers', ['name' => 'web-'.Str::random(4), 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->latest('id')->firstOrFail();
    $agent = servers_enroll_agent($server);
    servers_poll($agent['headers']);
    servers_finish($agent['headers'], $server->refresh()->provision_command_id);
    servers_poll($agent['headers']);

    return [$server->refresh(), $agent];
}

it('adds organization SSH keys with fingerprints and rejects duplicates / junk', function () {
    $this->post('/ssh-keys', ['name' => 'laptop', 'public_key' => $this->publicKey])->assertSessionHasNoErrors();

    $key = SshKey::query()->firstOrFail();
    expect($key->fingerprint)->toStartWith('SHA256:')
        ->and($key->public_key)->not->toContain('me@laptop');

    $this->post('/ssh-keys', ['name' => 'again', 'public_key' => $this->publicKey])->assertSessionHasErrors('public_key');
    $this->post('/ssh-keys', ['name' => 'junk', 'public_key' => 'ssh-rsa nope'])->assertSessionHasErrors('public_key');

    $this->get('/ssh-keys')->assertOk()->assertInertia(fn ($page) => $page->component('Servers/SshKeys', false)->has('keys', 1)->where('keys.0.servers_count', 0));
});

it('attaches keys to servers and syncs authorized_keys with a schema-valid payload', function () {
    [$server, $agent] = activeServerWithAgent();
    $this->post('/ssh-keys', ['name' => 'laptop', 'public_key' => $this->publicKey]);
    $key = SshKey::query()->firstOrFail();

    $this->post("/servers/{$server->id}/ssh-keys", ['ssh_key_id' => $key->id, 'unix_user' => 'root'])->assertSessionHasNoErrors();

    $envelopes = collect(servers_poll($agent['headers']));
    $root = $envelopes->firstWhere('payload.user', 'root');

    expect($envelopes)->toHaveCount(2)
        ->and($root['payload']['keys'])->toBe([['id' => $key->id, 'name' => 'laptop', 'public_key' => $key->public_key]])
        ->and($envelopes->firstWhere('payload.user', 'kiln')['payload']['keys'])->toBe([])
        ->and(app(ProtocolSchemas::class)->validateCommand('system.ssh_key.sync', ProtocolSchemas::toJson($root['payload'])))->toBe([]);

    $this->post("/servers/{$server->id}/ssh-keys", ['ssh_key_id' => $key->id, 'unix_user' => 'postgres'])->assertSessionHasErrors('unix_user');

    $this->delete("/servers/{$server->id}/ssh-keys/{$key->id}")->assertSessionHasNoErrors();
    expect(collect(servers_poll($agent['headers']))->firstWhere('payload.user', 'root')['payload']['keys'])->toBe([]);
});

it('deleting a key removes it from every server', function () {
    [$server, $agent] = activeServerWithAgent();
    $this->post('/ssh-keys', ['name' => 'laptop', 'public_key' => $this->publicKey]);
    $key = SshKey::query()->firstOrFail();
    $this->post("/servers/{$server->id}/ssh-keys", ['ssh_key_id' => $key->id, 'unix_user' => 'kiln']);
    servers_poll($agent['headers']);

    $this->delete("/ssh-keys/{$key->id}")->assertSessionHasNoErrors();

    expect(SshKey::query()->count())->toBe(0)
        ->and(collect(servers_poll($agent['headers']))->firstWhere('payload.user', 'kiln')['payload']['keys'])->toBe([]);
});

it('does not sync keys to servers that are not active yet', function () {
    $this->post('/servers', ['name' => 'pending', 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->firstOrFail();
    $this->post('/ssh-keys', ['name' => 'laptop', 'public_key' => $this->publicKey]);

    $this->post("/servers/{$server->id}/ssh-keys", ['ssh_key_id' => SshKey::query()->value('id'), 'unix_user' => 'kiln'])->assertSessionHasNoErrors();

    expect(Command::query()->count())->toBe(0);
});

it('scopes keys to the organization', function () {
    [$outsider] = memberOf();
    $this->post('/ssh-keys', ['name' => 'laptop', 'public_key' => $this->publicKey]);
    $key = SshKey::query()->firstOrFail();

    $this->actingAs($outsider)->delete("/ssh-keys/{$key->id}")->assertNotFound();
    expect(SshKey::query()->count())->toBe(1);
});
