<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Application\OctanePorts;
use Kiln\Sites\Contracts\Data\LaravelSettings;
use Kiln\Sites\Contracts\OctaneServer;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->a = sites_server($this->organization->id, ['name' => 'web-1']);
    $this->b = sites_server($this->organization->id, ['name' => 'web-2']);
});

function octane_site(object $test, string $name, array $serverIds): Site
{
    $test->post('/sites', sites_input($serverIds, ['name' => $name]))->assertSessionHasNoErrors();

    return Site::query()->where('name', $name)->with('targets')->firstOrFail();
}

function octane_enable(object $test, Site $site, ?string $server = null): LaravelSettings
{
    $test->put("/sites/{$site->id}/laravel", array_filter(['scheduler' => false, 'horizon' => false, 'octane' => true, 'maintenance' => false, 'octane_server' => $server], fn ($v) => $v !== null))
        ->assertSessionHasNoErrors();

    return $site->refresh()->laravel;
}

it('persists a stable Octane server and port when Octane is switched on, and keeps them when it is off', function () {
    $site = octane_site($this, 'Shop', [$this->a->id]);
    $settings = octane_enable($this, $site);
    $expected = 8000 + (crc32(strtolower($site->id)) % 1000);

    expect($settings->octaneServer)->toBe(OctaneServer::FrankenPhp)
        ->and($settings->octanePort)->toBe($expected)
        ->and($settings->octaneAuxPort())->toBe($expected + 10000);

    // Off: kept, but no longer reserved; on again: the same port.
    $this->put("/sites/{$site->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => false, 'maintenance' => false])->assertSessionHasNoErrors();
    expect($site->refresh()->laravel)->octane->toBeFalse()->octanePort->toBe($expected);
    expect(octane_enable($this, $site)->octanePort)->toBe($expected);

    // The request can never choose the port.
    $this->put("/sites/{$site->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => true, 'maintenance' => false, 'octane_port' => 9999]);
    expect($site->refresh()->laravel->octanePort)->toBe($expected);
});

it('detects port collisions among the sites of a server and picks the next free port', function () {
    config(['sites.octane_port_span' => 3]);
    $first = octane_site($this, 'One', [$this->a->id]);
    $second = octane_site($this, 'Two', [$this->a->id]);
    $third = octane_site($this, 'Three', [$this->a->id]);
    $fourth = octane_site($this, 'Four', [$this->a->id]);

    $ports = [octane_enable($this, $first)->octanePort, octane_enable($this, $second)->octanePort, octane_enable($this, $third)->octanePort];
    sort($ports);

    expect($ports)->toBe([8000, 8001, 8002]);

    // Range exhausted on this server.
    $this->put("/sites/{$fourth->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => true, 'maintenance' => false])->assertSessionHasErrors('octane');
});

it('never hands out a port, or an admin port, that another site uses on the same server', function () {
    $ports = app(OctanePorts::class);
    $site = octane_site($this, 'Shop', [$this->a->id]);
    $other = octane_site($this, 'Other', [$this->a->id]);
    $elsewhere = octane_site($this, 'Elsewhere', [$this->b->id]);
    $other->forceFill(['app_port' => 8100])->save();
    $elsewhere->forceFill(['laravel' => new LaravelSettings(octane: true, octaneServer: OctaneServer::FrankenPhp, octanePort: 8300)])->save();
    $third = octane_site($this, 'Third', [$this->a->id]);
    $third->forceFill(['laravel' => new LaravelSettings(octane: true, octaneServer: OctaneServer::FrankenPhp, octanePort: 8200)])->save();

    // Other's app port and Third's Octane port are taken; Elsewhere's port is only used on another server.
    expect($ports->port($site->id, [$this->a->id], 8100))->not->toBe(8100)
        ->and($ports->port($site->id, [$this->a->id], 8200))->not->toBe(8200)
        ->and($ports->port($site->id, [$this->a->id], 8300))->toBe(8300)
        ->and($ports->port($site->id, [$this->a->id], 8500))->toBe(8500);

    // A port whose admin port (+10000) is used by another site is not free either.
    $other->forceFill(['app_port' => 18400])->save();
    expect($ports->port($site->id, [$this->a->id], 8400))->not->toBe(8400);
});

it('moves the Octane port when a new server of the site already uses it', function () {
    $site = octane_site($this, 'Shop', [$this->a->id]);
    $port = octane_enable($this, $site)->octanePort;
    $busy = octane_site($this, 'Busy', [$this->b->id]);
    $busy->forceFill(['laravel' => new LaravelSettings(octane: true, octaneServer: OctaneServer::FrankenPhp, octanePort: $port)])->save();

    $this->put("/sites/{$site->id}/targets", ['server_ids' => [$this->a->id, $this->b->id], 'leader_server_id' => $this->a->id])->assertSessionHasNoErrors();

    expect($site->refresh()->laravel->octanePort)->not->toBe($port)->toBeGreaterThanOrEqual(8000);
});

it('validates the Octane server against the runtime', function () {
    $fpm = sites_server($this->organization->id, ['name' => 'fpm-1'], ['8.4'], 'fpm');
    $this->post('/sites', sites_input([$fpm->id], ['name' => 'Legacy', 'runtime' => 'php-fpm']))->assertSessionHasNoErrors();
    $site = Site::query()->where('name', 'Legacy')->firstOrFail();

    $this->put("/sites/{$site->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => true, 'maintenance' => false, 'octane_server' => 'frankenphp'])
        ->assertSessionHasErrors('octane_server');
    $this->put("/sites/{$site->id}/laravel", ['scheduler' => false, 'horizon' => false, 'octane' => true, 'maintenance' => false, 'octane_server' => 'nginx'])
        ->assertSessionHasErrors('octane_server');

    expect(octane_enable($this, $site)->octaneServer)->toBe(OctaneServer::Swoole)
        ->and(octane_enable($this, $site, 'roadrunner')->octaneServer)->toBe(OctaneServer::RoadRunner);

    $json = $this->getJson("/sites/{$site->id}/settings")->json('data.settings');
    expect($json['laravel'])->toMatchArray(['octane' => true, 'octane_server' => 'roadrunner'])
        ->and(array_column($json['octane_servers'], 'value'))->toBe(['swoole', 'roadrunner']);

    // FrankenPHP site: frankenphp is the default.
    $shop = octane_site($this, 'Shop', [$this->a->id]);
    expect(octane_enable($this, $shop)->octaneServer)->toBe(OctaneServer::FrankenPhp);
});

it('gives a duplicated site sharing the servers its own Octane port', function () {
    $site = octane_site($this, 'Shop', [$this->a->id]);
    $port = octane_enable($this, $site)->octanePort;

    $copy = app(SiteFactory::class)->duplicate($site->id, ['name' => 'Shop copy', 'server_ids' => [$this->a->id], 'leader_server_id' => $this->a->id], null, $this->user->id);

    expect($copy->site->laravel->octane)->toBeTrue()
        ->and($copy->site->laravel->octanePort)->not->toBe($port);
});

it('switches Octane through the API', function () {
    $site = octane_site($this, 'Shop', [$this->a->id]);
    $token = $this->user->createToken('e2e')->plainTextToken;

    $response = $this->withToken($token)->putJson("/api/v1/sites/{$site->id}/laravel", ['octane' => true])->assertOk();

    expect($response->json('data'))->toMatchArray(['octane' => true, 'octane_server' => 'frankenphp', 'scheduler' => $site->laravel->scheduler])
        ->and($response->json('data.octane_port'))->toBeInt();

    $this->withToken($token)->putJson("/api/v1/sites/{$site->id}/laravel", ['octane_server' => 'bogus'])->assertUnprocessable();
    $this->withToken($token)->putJson("/api/v1/sites/{$site->id}/laravel", ['octane' => false])->assertOk()->assertJsonPath('data.octane', false);
});
