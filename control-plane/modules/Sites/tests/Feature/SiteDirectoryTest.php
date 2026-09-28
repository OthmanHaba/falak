<?php

use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteHeaders;
use Kiln\Sites\Domain\Models\Site;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    sites_fake_agents();
    $this->git = sites_fake_source_control();
    config(['sites.test_domain' => 'preview.kiln.dev']);
});

it('looks sites up by organization, server, repository and leader', function () {
    $a = sites_server($this->organization->id, ['name' => 'web-1']);
    $b = sites_server($this->organization->id, ['name' => 'web-2']);
    $connection = $this->git->addConnection($this->organization->id);

    $this->post('/sites', sites_input([$a->id, $b->id], ['name' => 'Shop', 'leader_server_id' => $b->id, 'source_connection_id' => $connection->id, 'repository' => 'Acme/Shop', 'branch' => 'main']));
    $this->post('/sites', sites_input([$b->id], ['name' => 'Blog', 'framework' => 'static', 'runtime' => 'static', 'php_version' => null]));

    $directory = app(SiteDirectory::class);
    $shop = Site::query()->where('name', 'Shop')->firstOrFail();

    expect(array_map(fn ($s) => $s->name, $directory->forOrganization($this->organization->id)))->toBe(['Blog', 'Shop'])
        ->and(array_map(fn ($s) => $s->name, $directory->forServer($a->id)))->toBe(['Shop'])
        ->and(array_map(fn ($s) => $s->name, $directory->forServer($b->id)))->toBe(['Blog', 'Shop'])
        ->and($directory->forRepository($connection->id, 'acme/shop', 'main'))->toHaveCount(1)
        ->and($directory->forRepository($connection->id, 'acme/shop', 'develop'))->toBe([])
        ->and($directory->leader($shop->id)->serverId)->toBe($b->id)
        ->and($directory->targets($shop->id))->toHaveCount(2)
        ->and(array_map(fn ($p) => $p->path, $directory->sharedPaths($shop->id)))->toBe(['storage', '.env']);

    $data = $directory->find($shop->id);
    expect($data->testDomain)->toBe('shop.preview.kiln.dev')
        ->and($data->documentRoot())->toBe('/srv/kiln/sites/shop/current/public')
        ->and($data->serverIds())->toBe([$b->id, $a->id])
        ->and($data->leader()->serverId)->toBe($b->id);

    $blog = $directory->forServer($b->id)[0];
    expect($blog->documentRoot())->toBe('/srv/kiln/sites/blog/current')->and($blog->fpmSocket())->toBeNull();

    $header = app(SiteHeaders::class)->for($shop->id);
    expect($header['servers'][0])->toBe(['id' => $b->id, 'name' => 'web-2', 'role' => 'leader'])
        ->and($header['test_domain'])->toBe('shop.preview.kiln.dev');
});

it('deletes sites of a deleted organization', function () {
    $server = sites_server($this->organization->id);
    $this->post('/sites', sites_input([$server->id]));

    OrganizationDeleted::dispatch($this->organization->id);

    expect(Site::query()->count())->toBe(0);
});

it('lists, searches and serves the API', function () {
    $server = sites_server($this->organization->id);
    $this->post('/sites', sites_input([$server->id]));
    $site = Site::query()->firstOrFail();

    $this->getJson('/sites/search?q=sho')->assertOk()->assertJsonPath('data.0.id', $site->id);
    $this->getJson('/api/v1/sites')->assertOk()->assertJsonPath('data.0.slug', 'shop')->assertJsonMissingPath('data.0.environment');
    $this->getJson("/api/v1/sites/{$site->id}")->assertOk()->assertJsonPath('data.targets.0.server_id', $server->id);

    [$stranger] = memberOf(null, Role::Owner);
    $this->actingAs($stranger)->getJson('/sites/search?q=sho')->assertJsonPath('data', []);
    $this->actingAs($stranger)->getJson("/api/v1/sites/{$site->id}")->assertNotFound();
});
