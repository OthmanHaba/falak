<?php

use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'web-1']);
});

it('masks the site secrets in site commands, which read the .env', function () {
    $this->post('/sites', sites_input([$this->server->id]));
    $site = Site::query()->firstOrFail();
    EnvironmentVersion::query()->create([
        'site_id' => $site->id, 'version' => 99, 'variables' => ['APP_KEY' => 'base64:secret', 'APP_ENV' => 'production', 'DB_PASSWORD' => 'pw-123456'],
        'exposed' => [], 'changed_keys' => [], 'created_at' => now(),
    ]);

    $this->post("/sites/{$site->id}/commands", ['command' => 'php artisan about'])->assertSessionHasNoErrors();

    $exec = $this->agents->last('system.exec')['payload'];
    expect($exec['site'])->toBe($site->slug)
        ->and($exec['mask'])->toBe(['APP_KEY', 'DB_PASSWORD'])
        ->and(json_encode($exec))->not->toContain('pw-123456');
});

it('re-applies isolated PHP-FPM pools when the agent is upgraded, so they may open the tmpfs env', function () {
    $server = sites_server($this->organization->id, ['name' => 'fpm-1'], php: ['8.3'], phpRuntime: 'fpm');
    $this->server = $server;
    $this->post('/sites', sites_input([$server->id], ['runtime' => 'php-fpm', 'php_version' => '8.3', 'isolated' => true]))->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();
    $before = count($this->agents->ofType('runtime.fpm.pool'));

    event(new AgentVersionChanged('agent', $this->organization->id, $this->server->id, '0.9.0', '0.10.0', []));

    $pools = $this->agents->ofType('runtime.fpm.pool');
    expect($pools)->toHaveCount($before + 1)
        ->and(end($pools)['payload']['php_admin_values']['open_basedir'])
        ->toContain(":/run/falak/env/{$site->slug}.env:/run/falak/env/{$site->slug}.d/");

    // Another server's upgrade leaves this site's pool alone.
    $other = sites_server($this->organization->id, ['name' => 'web-2']);
    event(new AgentVersionChanged('agent', $this->organization->id, $other->id, '0.9.0', '0.10.0', []));
    expect($this->agents->ofType('runtime.fpm.pool'))->toHaveCount($before + 1);
});
