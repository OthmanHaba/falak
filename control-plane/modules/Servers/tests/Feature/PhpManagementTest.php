<?php

use Illuminate\Support\Facades\Event;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Events\PhpVersionChanged;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test']);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);

    $this->post('/servers', ['name' => 'web-1', 'type' => 'web', 'provider' => 'custom', 'stack' => ['php' => ['runtime' => 'fpm', 'versions' => ['8.4'], 'default' => '8.4']]]);
    $this->server = Server::query()->firstOrFail();
    $this->agent = servers_enroll_agent($this->server, ['memory_bytes' => 4 * 1024 ** 3]);
    servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $this->server->refresh()->provision_command_id);
    servers_poll($this->agent['headers']); // ssh key syncs
});

function schemaErrorsFor(array $envelope): array
{
    return app(ProtocolSchemas::class)->validateCommand($envelope['type'], ProtocolSchemas::toJson($envelope['payload']));
}

it('installs a PHP version through runtime.php.install', function () {
    Event::fake([PhpVersionChanged::class]);

    $this->post("/servers/{$this->server->id}/php", ['version' => '8.3'])->assertSessionHasNoErrors();

    $php = $this->server->phpVersions()->where('version', '8.3')->firstOrFail();
    expect($php->status)->toBe(PhpVersionStatus::Installing)
        ->and($php->fpm['max_children'])->toBe(38);

    [$envelope] = servers_poll($this->agent['headers']);
    expect($envelope['type'])->toBe('runtime.php.install')
        ->and($envelope['payload'])->toMatchArray(['version' => '8.3', 'fpm' => true, 'cli_default' => false])
        ->and(schemaErrorsFor($envelope))->toBe([]);

    servers_finish($this->agent['headers'], $envelope['id']);

    expect($php->refresh()->status)->toBe(PhpVersionStatus::Installed);
    Event::assertDispatched(PhpVersionChanged::class, fn ($e) => $e->version === '8.3' && $e->change === 'installed');
});

it('marks failed installs and allows retrying', function () {
    $this->post("/servers/{$this->server->id}/php", ['version' => '8.2']);
    [$envelope] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $envelope['id'], 100, 'E: Unable to locate package php8.2-fpm');

    $php = $this->server->phpVersions()->where('version', '8.2')->firstOrFail();
    expect($php->status)->toBe(PhpVersionStatus::Failed)
        ->and($php->status_message)->toContain('Unable to locate package');

    $this->post("/servers/{$this->server->id}/php", ['version' => '8.2'])->assertSessionHasNoErrors();
    expect($php->refresh()->status)->toBe(PhpVersionStatus::Installing);
});

it('rejects duplicate and unsupported versions', function () {
    $this->post("/servers/{$this->server->id}/php", ['version' => '8.4'])->assertSessionHasErrors('version');
    $this->post("/servers/{$this->server->id}/php", ['version' => '5.6'])->assertSessionHasErrors('version');
});

it('switches the CLI default', function () {
    $this->post("/servers/{$this->server->id}/php", ['version' => '8.3']);
    [$install] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $install['id']);

    $this->put("/servers/{$this->server->id}/php/8.3/default")->assertSessionHasNoErrors();

    [$envelope] = servers_poll($this->agent['headers']);
    expect($envelope['payload'])->toMatchArray(['version' => '8.3', 'cli_default' => true])
        ->and(schemaErrorsFor($envelope))->toBe([])
        ->and($this->server->phpVersions()->where('is_default', true)->pluck('version')->all())->toBe(['8.3'])
        ->and($this->server->refresh()->stack->phpDefault)->toBe('8.3');
});

it('updates php.ini through runtime.php.configure and stores FPM defaults', function () {
    $fpm = ['pm' => 'static', 'max_children' => 20, 'start_servers' => 5, 'min_spare_servers' => 2, 'max_spare_servers' => 8, 'max_requests' => 1000];

    $this->put("/servers/{$this->server->id}/php/8.4/settings", [
        'ini' => ['memory_limit' => '1G', 'max_execution_time' => 120, 'opcache.enable' => true],
        'fpm' => $fpm,
    ])->assertSessionHasNoErrors();

    [$envelope] = servers_poll($this->agent['headers']);
    expect($envelope['type'])->toBe('runtime.php.configure')
        ->and($envelope['payload'])->toBe(['version' => '8.4', 'sapi' => 'all', 'ini' => ['memory_limit' => '1G', 'max_execution_time' => 120, 'opcache.enable' => true]])
        ->and(schemaErrorsFor($envelope))->toBe([])
        ->and($this->server->phpVersions()->first()->fpm)->toBe($fpm);

    // FPM-only change does not touch the host.
    $this->put("/servers/{$this->server->id}/php/8.4/settings", ['ini' => ['memory_limit' => '1G', 'max_execution_time' => 120, 'opcache.enable' => true], 'fpm' => [...$fpm, 'max_children' => 30]])->assertSessionHasNoErrors();
    expect(servers_poll($this->agent['headers']))->toBe([]);
});

it('validates php settings', function () {
    $fpm = ['pm' => 'dynamic', 'max_children' => 5, 'start_servers' => 10, 'min_spare_servers' => 1, 'max_spare_servers' => 3, 'max_requests' => 0];
    $this->put("/servers/{$this->server->id}/php/8.4/settings", ['ini' => [], 'fpm' => $fpm])->assertSessionHasErrors('fpm.start_servers');
    $this->put("/servers/{$this->server->id}/php/8.4/settings", ['ini' => ['Bad Key!' => '1'], 'fpm' => [...$fpm, 'start_servers' => 2]])->assertSessionHasErrors('ini');
});

it('removes a PHP version by converging the plan without it', function () {
    Event::fake([PhpVersionChanged::class]);
    $this->post("/servers/{$this->server->id}/php", ['version' => '8.3']);
    [$install] = servers_poll($this->agent['headers']);
    servers_finish($this->agent['headers'], $install['id']);

    $this->delete("/servers/{$this->server->id}/php/8.4")->assertSessionHasErrors('version'); // default

    $this->delete("/servers/{$this->server->id}/php/8.3")->assertSessionHasNoErrors();
    [$envelope] = servers_poll($this->agent['headers']);

    expect($envelope['type'])->toBe('provision.apply')
        ->and($envelope['payload']['runtimes']['php']['versions'])->toBe(['8.4'])
        ->and($this->server->refresh()->status->value)->toBe('active');

    servers_finish($this->agent['headers'], $envelope['id']);

    expect($this->server->phpVersions()->pluck('version')->all())->toBe(['8.4'])
        ->and($this->server->refresh()->status->value)->toBe('active');
    Event::assertDispatched(PhpVersionChanged::class, fn ($e) => $e->version === '8.3' && $e->change === 'removed');
});

it('refuses PHP management on servers that do not run PHP or are not active', function () {
    $db = Server::factory()->type(ServerType::Database)->create(['organization_id' => $this->organization->id]);
    $this->post("/servers/{$db->id}/php", ['version' => '8.3'])->assertSessionHasErrors('version');

    $pending = Server::factory()->status(ServerStatus::Provisioning)->create(['organization_id' => $this->organization->id]);
    $this->post("/servers/{$pending->id}/php", ['version' => '8.3'])->assertSessionHasErrors('version');
});
