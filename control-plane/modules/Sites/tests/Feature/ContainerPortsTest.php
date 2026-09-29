<?php

use Illuminate\Support\Facades\Event;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteUpdated;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'docker-1'], docker: true);
});

function docker_site_input(array $serverIds, array $overrides = []): array
{
    return sites_input($serverIds, ['framework' => 'docker', 'runtime' => 'docker', 'php_version' => null, 'docker_image' => 'ghcr.io/acme/api:1', ...$overrides]);
}

it('lets docker sites on one server listen on the same container port, each on its own host port', function () {
    $this->post('/sites', docker_site_input([$this->server->id], ['name' => 'Api', 'container_port' => 3000]))->assertSessionHasNoErrors();
    $this->post('/sites', docker_site_input([$this->server->id], ['name' => 'Web', 'container_port' => 3000]))->assertSessionHasNoErrors();

    $api = Site::query()->where('slug', 'api')->firstOrFail();
    $web = Site::query()->where('slug', 'web')->firstOrFail();

    expect($api->container_port)->toBe(3000)
        ->and($web->container_port)->toBe(3000)
        ->and($api->app_port)->not->toBe($web->app_port)
        ->and($api->toData()->listenPort())->toBe(3000);
});

it('treats app_port from older clients as the container port of a docker site', function () {
    $this->post('/sites', docker_site_input([$this->server->id], ['name' => 'Api', 'app_port' => 8080]))->assertSessionHasNoErrors();

    $site = Site::query()->firstOrFail();

    expect($site->container_port)->toBe(8080)->and($site->app_port)->toBe(3000);
});

it('defaults the container port to 3000 and keeps the host port when only the container port changes', function () {
    $this->post('/sites', docker_site_input([$this->server->id], ['name' => 'Api']))->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();
    $host = $site->app_port;

    expect($site->container_port)->toBe(3000);

    Event::fake([SiteUpdated::class]);
    $this->patch("/sites/{$site->id}", ['container_port' => 8080])->assertSessionHasNoErrors();

    expect($site->refresh()->container_port)->toBe(8080)->and($site->app_port)->toBe($host);
    Event::assertDispatched(SiteUpdated::class, fn (SiteUpdated $event) => $event->changed('container_port'));
});

it('stops a deleted docker site\'s blue and green containers on its servers', function () {
    $this->post('/sites', docker_site_input([$this->server->id], ['name' => 'Api']))->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();
    Event::fake([SiteDeleted::class]);

    $this->delete("/sites/{$site->id}", ['name' => 'Api'])->assertRedirect('/sites');

    $stops = $this->agents->ofType('docker.stop');
    expect($stops)->toHaveCount(2)
        ->and(array_column(array_column($stops, 'payload'), 'name'))->toBe(['kiln-api-blue', 'kiln-api-green'])
        ->and(array_unique(array_column(array_column($stops, 'payload'), 'remove')))->toBe([true])
        ->and(array_unique(array_column($stops, 'server_id')))->toBe([$this->server->id]);
});

it('stops a docker site\'s containers on a server removed from it', function () {
    $other = sites_server($this->organization->id, ['name' => 'docker-2'], docker: true);
    $this->post('/sites', docker_site_input([$this->server->id, $other->id], ['name' => 'Api']))->assertSessionHasNoErrors();
    $site = Site::query()->firstOrFail();

    $this->put("/sites/{$site->id}/targets", ['server_ids' => [$this->server->id], 'leader_server_id' => $this->server->id])->assertSessionHasNoErrors();

    $stops = $this->agents->ofType('docker.stop');
    expect($stops)->toHaveCount(2)->and(array_unique(array_column($stops, 'server_id')))->toBe([$other->id]);
});

it('moves a docker site\'s host port instead of refusing a server that already uses it', function () {
    $other = sites_server($this->organization->id, ['name' => 'docker-2'], docker: true);
    $this->post('/sites', docker_site_input([$this->server->id], ['name' => 'Api']))->assertSessionHasNoErrors();
    $this->post('/sites', docker_site_input([$other->id], ['name' => 'Web']))->assertSessionHasNoErrors();
    $web = Site::query()->where('slug', 'web')->firstOrFail();
    $api = Site::query()->where('slug', 'api')->firstOrFail();
    expect($web->app_port)->toBe($api->app_port);

    Event::fake([SiteUpdated::class]);
    $this->put("/sites/{$web->id}/targets", ['server_ids' => [$other->id, $this->server->id], 'leader_server_id' => $other->id])->assertSessionHasNoErrors();

    expect($web->refresh()->app_port)->not->toBe($api->app_port)->and($web->container_port)->toBe(3000);
    Event::assertDispatched(SiteUpdated::class, fn (SiteUpdated $event) => $event->siteId === $web->id && $event->changed('app_port'));
});
