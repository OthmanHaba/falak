<?php

use Illuminate\Support\Facades\Event;
use Kiln\Identity\Contracts\Role;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteCommand;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Sites\Events\SiteUpdated;
use Kiln\SourceControl\Events\ConnectionDeleted;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->a = sites_server($this->organization->id, ['name' => 'web-1'], ['8.3', '8.4'], 'fpm');
    $this->b = sites_server($this->organization->id, ['name' => 'web-2'], ['8.3', '8.4'], 'fpm');

    $this->post('/sites', sites_input([$this->a->id], ['runtime' => 'php-fpm']))->assertSessionHasNoErrors();
    $this->site = Site::query()->with('targets')->firstOrFail();
    sites_finish($this->agents->last('runtime.fpm.pool'));
});

it('updates general settings and announces what changed', function () {
    Event::fake([SiteUpdated::class]);

    $this->patch("/sites/{$this->site->id}", ['name' => 'Shop EU', 'web_directory' => '/web/', 'health_check_path' => '/health'])->assertSessionHasNoErrors();

    $site = $this->site->refresh();
    expect($site->name)->toBe('Shop EU')->and($site->web_directory)->toBe('web')
        ->and($site->toData()->documentRoot())->toBe('/srv/kiln/sites/shop/current/web');

    Event::assertDispatched(SiteUpdated::class, fn (SiteUpdated $e) => $e->changed(['name', 'web_directory', 'health_check_path'][0]) && $e->changed('web_directory') && ! $e->changed('runtime'));

    // No-op update does not fire.
    $this->patch("/sites/{$this->site->id}", ['name' => 'Shop EU']);
    Event::assertDispatchedTimes(SiteUpdated::class, 1);
});

it('moves the FPM pool when the PHP version changes', function () {
    $this->patch("/sites/{$this->site->id}", ['php_version' => '8.3'])->assertSessionHasNoErrors();

    $pools = $this->agents->ofType('runtime.fpm.pool');
    expect($pools)->toHaveCount(3)
        ->and($pools[1]['payload'])->toBe(['php_version' => '8.4', 'pool' => 'shop', 'user' => 'kiln', 'state' => 'absent'])
        ->and($pools[2]['payload']['php_version'])->toBe('8.3')
        ->and($pools[2]['payload']['listen'])->toBe('/run/php/kiln-shop-8.3.sock');
});

it('switches between php runtimes but not to containers', function () {
    $this->patch("/sites/{$this->site->id}", ['runtime' => 'docker'])->assertSessionHasErrors('runtime');
    $this->patch("/sites/{$this->site->id}", ['runtime' => 'frankenphp'])->assertSessionHasErrors('server_ids');
});

it('relinks the repository with a new deploy key', function () {
    $connection = $this->git->addConnection($this->organization->id);

    $this->patch("/sites/{$this->site->id}", ['source_connection_id' => $connection->id, 'repository' => 'acme/shop', 'branch' => 'main', 'push_to_deploy' => true])->assertSessionHasNoErrors();
    $first = $this->site->refresh()->deploy_key_id;

    $this->patch("/sites/{$this->site->id}", ['source_connection_id' => $connection->id, 'repository' => 'acme/store', 'branch' => 'main'])->assertSessionHasNoErrors();

    expect($this->site->refresh()->deploy_key_id)->not->toBe($first)->not->toBeNull()
        ->and($this->git->removedKeys)->toBe([$first])
        ->and($this->git->removedWebhooks)->toBe(["{$connection->id}|acme/shop"])
        ->and($this->git->webhooks)->toHaveKey("{$connection->id}|acme/store");
});

it('adds and removes servers and changes the leader', function () {
    Event::fake([SiteTargetsChanged::class]);

    $this->put("/sites/{$this->site->id}/targets", ['server_ids' => [$this->a->id, $this->b->id], 'leader_server_id' => $this->b->id])->assertSessionHasNoErrors();

    $site = $this->site->refresh()->load('targets');
    expect($site->targets)->toHaveCount(2)
        ->and($site->leaderTarget()->server_id)->toBe($this->b->id)
        ->and($this->agents->last('runtime.fpm.pool')['server_id'])->toBe($this->b->id);

    Event::assertDispatched(SiteTargetsChanged::class, fn ($e) => $e->added === [$this->b->id] && $e->removed === [] && $e->leaderServerId === $this->b->id);

    $this->put("/sites/{$this->site->id}/targets", ['server_ids' => [$this->b->id], 'leader_server_id' => $this->b->id])->assertSessionHasNoErrors();

    expect($this->site->refresh()->targets()->pluck('server_id')->all())->toBe([$this->b->id])
        ->and($this->agents->last('runtime.fpm.pool')['payload']['state'])->toBe('absent')
        ->and($this->agents->last('runtime.fpm.pool')['server_id'])->toBe($this->a->id);

    Event::assertDispatched(SiteTargetsChanged::class, fn ($e) => $e->removed === [$this->a->id]);

    $this->put("/sites/{$this->site->id}/targets", ['server_ids' => [$this->b->id], 'leader_server_id' => $this->a->id])->assertSessionHasErrors('leader_server_id');
});

it('promotes a new leader when a server is deleted', function () {
    $this->put("/sites/{$this->site->id}/targets", ['server_ids' => [$this->a->id, $this->b->id], 'leader_server_id' => $this->a->id]);
    Event::fake([SiteTargetsChanged::class]);

    ServerDeleted::dispatch($this->a->id, $this->organization->id, 'web', 'web-1');

    $targets = $this->site->refresh()->targets;
    expect($targets)->toHaveCount(1)->and($targets->first()->role)->toBe(TargetRole::Leader);
    Event::assertDispatched(SiteTargetsChanged::class, fn ($e) => $e->removed === [$this->a->id] && $e->leaderServerId === $this->b->id);
});

it('validates shared paths', function () {
    $this->put("/sites/{$this->site->id}/shared-paths", ['paths' => [['path' => '../etc', 'type' => 'directory']]])->assertSessionHasErrors('paths.0.path');

    $this->put("/sites/{$this->site->id}/shared-paths", ['paths' => [
        ['path' => '/storage/', 'type' => 'directory'],
        ['path' => 'public/uploads', 'type' => 'directory'],
        ['path' => '.env', 'type' => 'file'],
        ['path' => 'storage', 'type' => 'directory'],
    ]])->assertSessionHasNoErrors();

    expect(array_map(fn ($p) => $p->toArray(), $this->site->refresh()->shared_paths))->toBe([
        ['path' => 'storage', 'type' => 'directory'],
        ['path' => 'public/uploads', 'type' => 'directory'],
        ['path' => '.env', 'type' => 'file'],
    ]);
});

it('toggles maintenance mode with artisan on every ready server', function () {
    Event::fake([SiteUpdated::class]);

    $this->put("/sites/{$this->site->id}/laravel", ['scheduler' => true, 'horizon' => true, 'octane' => false, 'maintenance' => true])->assertSessionHasNoErrors();

    $exec = $this->agents->last('system.exec');
    expect($exec['payload']['script'])->toContain('php8.4 artisan down --retry=60')->toContain("cd '/srv/kiln/sites/shop/current'")
        ->and($exec['payload']['user'])->toBe('kiln')
        ->and($exec['payload']['env']['KILN_IS_LEADER'])->toBe('1')
        ->and($this->site->refresh()->laravel->horizon)->toBeTrue()
        ->and(SiteCommand::query()->count())->toBe(1);

    Event::assertDispatched(SiteUpdated::class, fn ($e) => $e->changed('laravel.horizon') && $e->changed('laravel.maintenance'));

    $this->put("/sites/{$this->site->id}/laravel", ['scheduler' => true, 'horizon' => true, 'octane' => false, 'maintenance' => false]);
    expect($this->agents->last('system.exec')['payload']['script'])->toContain('artisan up');
});

it('deletes a site after confirmation and cleans up remotely', function () {
    Event::fake([SiteDeleted::class]);
    $connection = $this->git->addConnection($this->organization->id);
    $this->patch("/sites/{$this->site->id}", ['source_connection_id' => $connection->id, 'repository' => 'acme/shop', 'branch' => 'main']);
    $key = $this->site->refresh()->deploy_key_id;

    $this->delete("/sites/{$this->site->id}", ['name' => 'Shop'])->assertForbidden();

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin)->delete("/sites/{$this->site->id}", ['name' => 'nope'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->delete("/sites/{$this->site->id}", ['name' => 'Shop'])->assertRedirect('/sites');

    expect(Site::query()->count())->toBe(0)
        ->and($this->agents->last('runtime.fpm.pool')['payload']['state'])->toBe('absent')
        ->and($this->git->removedKeys)->toBe([$key]);
    Event::assertDispatched(SiteDeleted::class, fn ($e) => $e->serverIds === [$this->a->id] && $e->slug === 'shop');
});

it('detaches sites when their source control connection is deleted', function () {
    $connection = $this->git->addConnection($this->organization->id);
    $this->patch("/sites/{$this->site->id}", ['source_connection_id' => $connection->id, 'repository' => 'acme/shop', 'branch' => 'main', 'push_to_deploy' => true]);

    ConnectionDeleted::dispatch($connection->id, $this->organization->id, 'github');

    $site = $this->site->refresh();
    expect($site->source_connection_id)->toBeNull()->and($site->deploy_key_id)->toBeNull()->and($site->push_to_deploy)->toBeFalse()
        ->and($site->repository)->toBe('acme/shop');
});

it('hides sites of other organizations and blocks viewers from changes', function () {
    [$stranger] = memberOf(null, Role::Owner);
    $this->actingAs($stranger)->get("/sites/{$this->site->id}")->assertNotFound();
    $this->actingAs($stranger)->patch("/sites/{$this->site->id}", ['name' => 'x'])->assertNotFound();

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->getJson("/sites/{$this->site->id}/settings")->assertOk()
        ->assertJsonPath('data.can.update', false)
        ->assertJsonPath('data.settings.name', $this->site->name);
    $this->actingAs($viewer)->get("/sites/{$this->site->id}/settings")->assertRedirect();
    $this->actingAs($viewer)->getJson("/sites/{$this->site->id}/deploy-script")->assertOk()->assertJsonPath('data.can.update', false);
    $this->actingAs($viewer)->getJson("/sites/{$this->site->id}/commands")->assertOk()->assertJsonPath('data.can.run', false);
    $this->actingAs($viewer)->patch("/sites/{$this->site->id}", ['name' => 'x'])->assertForbidden();
    $this->actingAs($viewer)->put("/sites/{$this->site->id}/laravel", ['scheduler' => true, 'horizon' => false, 'octane' => false, 'maintenance' => false])->assertForbidden();
});
