<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\Server;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['fleet.ca_path' => sys_get_temp_dir().'/kiln-ca-test']);
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
});

it('provisions Ubuntu 26.04 with the default stack using the PHP version the OS has', function () {
    // The default stack (FrankenPHP, PHP 8.4) is chosen before the OS is known.
    $this->post('/servers', ['name' => 'web-1', 'type' => 'web', 'provider' => 'custom'])->assertSessionHasNoErrors();
    $server = Server::query()->firstOrFail();
    expect($server->stack->phpVersions)->toBe(['8.4']);

    $agent = servers_enroll_agent($server, ['os' => ['id' => 'ubuntu', 'version' => '26.04']]);
    $server->refresh();

    [$plan] = servers_poll($agent['headers']);
    expect($plan['type'])->toBe('provision.apply')
        ->and($plan['payload']['runtimes']['php'])->toMatchArray(['versions' => ['8.5'], 'default' => '8.5'])
        ->and($server->status)->toBe(ServerStatus::Provisioning)
        ->and($server->status_message)->toBe('Applying provisioning plan. PHP 8.4 is not available on Ubuntu 26.04; installing PHP 8.5 instead.')
        ->and($server->stack->phpVersions)->toBe(['8.5'])
        ->and($server->stack->phpDefault)->toBe('8.5')
        ->and($server->phpVersions()->pluck('version')->all())->toBe(['8.5'])
        ->and($server->defaultPhp()?->version)->toBe('8.5');

    servers_finish($agent['headers'], $plan['id']);
    expect($server->refresh()->phpVersions()->where('status', PhpVersionStatus::Installed)->pluck('version')->all())->toBe(['8.5'])
        ->and($server->status)->toBe(ServerStatus::Active);
});

it('keeps installable versions and only drops the others', function () {
    $this->post('/servers', ['name' => 'web-2', 'type' => 'web', 'provider' => 'custom', 'stack' => ['php' => ['runtime' => 'fpm', 'versions' => ['8.4', '8.5'], 'default' => '8.4']]]);
    $server = Server::query()->firstOrFail();

    $agent = servers_enroll_agent($server, ['os' => ['id' => 'ubuntu', 'version' => '26.04']]);

    [$plan] = servers_poll($agent['headers']);
    expect($plan['payload']['runtimes']['php'])->toMatchArray(['versions' => ['8.5'], 'default' => '8.5'])
        ->and($server->refresh()->status_message)->toBe('Applying provisioning plan. PHP 8.4 is not available on Ubuntu 26.04; left out of the plan.')
        ->and($server->stack->phpVersions)->toBe(['8.5']);
});

it('changes nothing on releases without restrictions', function () {
    $this->post('/servers', ['name' => 'web-3', 'type' => 'web', 'provider' => 'custom']);
    $server = Server::query()->firstOrFail();

    $agent = servers_enroll_agent($server);

    [$plan] = servers_poll($agent['headers']);
    expect($plan['payload']['runtimes']['php']['versions'])->toBe(['8.4'])
        ->and($server->refresh()->status_message)->toBe('Applying provisioning plan.');
});
