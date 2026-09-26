<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->git = sites_fake_source_control();
});

it('creates a site through the API with the same rules as the web form', function () {
    $server = sites_server($this->organization->id, ['name' => 'web-1']);
    $connection = $this->git->addConnection($this->organization->id);

    $this->postJson('/api/v1/sites', sites_input([$server->id], [
        'source_connection_id' => $connection->id,
        'repository' => 'acme/shop',
        'branch' => 'main',
    ]))
        ->assertCreated()
        ->assertJsonPath('data.slug', 'shop')
        ->assertJsonPath('data.targets.0.server_id', $server->id)
        ->assertJsonPath('warnings', []);

    expect(Site::query()->count())->toBe(1);
});

it('returns 422 JSON for invalid input', function () {
    $this->postJson('/api/v1/sites', ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'framework', 'server_ids']);
});

it('requires sites.create', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $server = sites_server($this->organization->id);

    $this->actingAs($viewer)->postJson('/api/v1/sites', sites_input([$server->id]))->assertForbidden();
});
