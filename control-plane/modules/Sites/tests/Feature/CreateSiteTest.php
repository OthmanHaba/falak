<?php

use Illuminate\Support\Facades\Event;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteCreated;
use Kiln\SourceControl\Contracts\ProviderType;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->git = sites_fake_source_control();
    config(['sites.test_domain' => 'kiln.test']);
});

it('creates a Laravel site on several servers with a leader, preset defaults and an initial environment', function () {
    Event::fake([SiteCreated::class]);
    $a = sites_server($this->organization->id, ['name' => 'web-1']);
    $b = sites_server($this->organization->id, ['name' => 'web-2']);
    $connection = $this->git->addConnection($this->organization->id);

    $this->post('/sites', sites_input([$a->id, $b->id], [
        'leader_server_id' => $b->id,
        'source_connection_id' => $connection->id,
        'repository' => 'acme/shop',
        'branch' => 'main',
        'push_to_deploy' => true,
    ]))->assertSessionHasNoErrors()->assertRedirect();

    $site = Site::query()->with('targets')->firstOrFail();

    expect($site->slug)->toBe('shop')
        ->and($site->runtime)->toBe(SiteRuntime::FrankenPhp)
        ->and($site->build_mode)->toBe(BuildMode::Native)
        ->and($site->web_directory)->toBe('public')
        ->and($site->unix_user)->toBe('kiln')
        ->and($site->laravel->scheduler)->toBeTrue()
        ->and(array_map(fn ($p) => $p->path, $site->shared_paths))->toBe(['storage', '.env'])
        ->and($site->deploy_script)->toContain('$KILN_FETCH')->toContain('$KILN_ACTIVATE')->toContain('artisan migrate --force')
        ->and($site->testDomain())->toBe('shop.kiln.test')
        ->and($site->deploy_key_id)->not->toBeNull()
        ->and($this->git->webhooks)->toHaveCount(1)
        ->and($site->targets)->toHaveCount(2)
        ->and($site->leaderTarget()->server_id)->toBe($b->id)
        ->and($site->targets->firstWhere('server_id', $a->id)->role)->toBe(TargetRole::Member)
        // FrankenPHP + shared unix user: nothing to prepare on the servers (Processes then converges the scheduler).
        ->and($site->targets->every(fn ($t) => $t->status === TargetStatus::Ready))->toBeTrue()
        ->and(array_values(array_filter($this->agents->dispatched, fn ($c) => ! in_array($c['type'], ['proc.apply', 'cron.apply'], true))))->toBe([]);

    $env = app(SiteDirectory::class)->environment($site->id);
    expect($env->version)->toBe(1)
        ->and($env->variables['APP_NAME'])->toBe('Shop')
        ->and($env->variables['APP_KEY'])->toStartWith('base64:')
        ->and($env->variables['APP_URL'])->toBe('https://shop.kiln.test');

    Event::assertDispatched(SiteCreated::class, fn (SiteCreated $e) => $e->siteId === $site->id && $e->serverIds === [$a->id, $b->id]);
    expect(AuditEntry::query()->where('action', 'site.created')->exists())->toBeTrue();
});

it('prepares isolated php-fpm sites: unix user, then the FPM pool', function () {
    $server = sites_server($this->organization->id, php: ['8.3', '8.4'], phpRuntime: 'fpm');

    $this->post('/sites', sites_input([$server->id], ['runtime' => 'php-fpm', 'php_version' => '8.3', 'isolated' => true, 'name' => '1 Blog']))->assertSessionHasNoErrors();

    $site = Site::query()->with('targets')->firstOrFail();
    $target = $site->targets->first();

    expect($site->unix_user)->toBe('s1-blog')
        ->and($target->status)->toBe(TargetStatus::Provisioning)
        ->and($target->step)->toBe('user');

    $user = $this->agents->last('system.user.create');
    expect($user['payload'])->toBe(['name' => 's1-blog', 'home' => '/srv/kiln/sites/1-blog', 'shell' => '/bin/bash', 'isolated' => true]);

    sites_finish($user);

    $pool = $this->agents->last('runtime.fpm.pool');
    expect($pool['payload'])->toMatchArray([
        'php_version' => '8.3',
        'pool' => '1-blog',
        'user' => 's1-blog',
        'listen' => '/run/php/kiln-1-blog-8.3.sock',
        'state' => 'present',
    ])->and($pool['payload']['php_admin_values']['open_basedir'])->toStartWith('/srv/kiln/sites/1-blog/')
        ->and($target->refresh()->step)->toBe('pool');

    sites_finish($pool);

    expect($target->refresh()->status)->toBe(TargetStatus::Ready)
        ->and($site->toData()->fpmSocket())->toBe('/run/php/kiln-1-blog-8.3.sock');
});

it('marks a target failed when a preparation command fails and retries it', function () {
    $server = sites_server($this->organization->id, phpRuntime: 'fpm');
    $this->post('/sites', sites_input([$server->id], ['runtime' => 'php-fpm']));
    $site = Site::query()->with('targets')->firstOrFail();
    $target = $site->targets->first();

    sites_finish($this->agents->last('runtime.fpm.pool'), success: false, error: 'php8.4-fpm is not installed');

    expect($target->refresh()->status)->toBe(TargetStatus::Failed)
        ->and($target->status_message)->toContain('PHP-FPM pool')->toContain('not installed');

    $this->post("/sites/{$site->id}/targets/{$target->id}/retry")->assertSessionHasNoErrors();

    expect($this->agents->ofType('runtime.fpm.pool'))->toHaveCount(2)
        ->and($target->refresh()->status)->toBe(TargetStatus::Provisioning);
});

it('fails the target when the agent is offline', function () {
    $server = sites_server($this->organization->id, phpRuntime: 'fpm');
    $this->agents->offline = [$server->id];

    $this->post('/sites', sites_input([$server->id], ['runtime' => 'php-fpm']))->assertSessionHasNoErrors();

    expect(Site::query()->firstOrFail()->targets()->first()->status)->toBe(TargetStatus::Failed);
});

it('allocates distinct app ports for node sites sharing a server', function () {
    $server = sites_server($this->organization->id);

    $this->post('/sites', sites_input([$server->id], ['name' => 'App', 'framework' => 'next', 'runtime' => 'node', 'php_version' => null]))->assertSessionHasNoErrors();
    $this->post('/sites', sites_input([$server->id], ['name' => 'Web', 'framework' => 'nuxt', 'runtime' => 'bun', 'php_version' => null]))->assertSessionHasNoErrors();
    $this->post('/sites', sites_input([$server->id], ['name' => 'Api', 'framework' => 'node', 'runtime' => 'deno', 'php_version' => null, 'app_port' => 3000]))->assertSessionHasErrors('app_port');

    $sites = Site::query()->orderBy('app_port')->get();
    expect($sites->pluck('app_port')->all())->toBe([3000, 3001])
        ->and($sites->first()->php_version)->toBeNull()
        ->and($sites->first()->web_directory)->toBe('')
        ->and(app(SiteDirectory::class)->environment($sites->first()->id)->variables['PORT'])->toBe('3000');
});

it('validates runtime compatibility with the servers', function () {
    $fpm = sites_server($this->organization->id, phpRuntime: 'fpm');
    $lb = sites_server($this->organization->id, ['type' => ServerType::LoadBalancer]);
    $plain = sites_server($this->organization->id);

    $this->post('/sites', sites_input([$fpm->id]))->assertSessionHasErrors('server_ids');
    expect(session('errors')->first('server_ids'))->toContain('does not run FrankenPHP');
    $this->post('/sites', sites_input([$fpm->id], ['runtime' => 'php-fpm', 'php_version' => '8.2']))->assertSessionHasErrors('server_ids');
    expect(session('errors')->first('server_ids'))->toContain('PHP 8.2 is not installed');
    $this->post('/sites', sites_input([$lb->id]))->assertSessionHasErrors('server_ids');
    $this->post('/sites', sites_input([$plain->id], ['framework' => 'docker', 'runtime' => 'docker', 'php_version' => null]))->assertSessionHasErrors('server_ids');
    expect(session('errors')->first('server_ids'))->toContain('Docker is not installed');
    $this->post('/sites', sites_input([$plain->id], ['runtime' => 'node']))->assertSessionHasErrors('runtime');
    $this->post('/sites', sites_input([$plain->id], ['build_mode' => 'docker']))->assertSessionHasErrors('build_mode');
    $this->post('/sites', sites_input([$plain->id], ['leader_server_id' => $fpm->id]))->assertSessionHasErrors('leader_server_id');

    expect(Site::query()->count())->toBe(0);
});

it('rejects servers of other organizations and foreign source control connections', function () {
    [, $other] = memberOf();
    $foreign = sites_server($other->id);
    $mine = sites_server($this->organization->id);
    $connection = $this->git->addConnection($other->id);

    $this->post('/sites', sites_input([$foreign->id]))->assertSessionHasErrors('server_ids');
    $this->post('/sites', sites_input([$mine->id], ['source_connection_id' => $connection->id, 'repository' => 'acme/shop', 'branch' => 'main']))->assertSessionHasErrors('source_connection_id');
});

it('only allows on-server builds with at least 2 GB of RAM', function () {
    $small = sites_server($this->organization->id, ['name' => 'small']);
    $big = sites_server($this->organization->id, ['name' => 'big']);
    sites_fake_agent_memory([$small->id => 1024 ** 3, $big->id => 4 * 1024 ** 3]);

    $this->post('/sites', sites_input([$small->id], ['build_mode' => 'on-server']))->assertSessionHasErrors('build_mode');
    expect(session('errors')->first('build_mode'))->toContain('has 1 GB');
    $this->post('/sites', sites_input([$big->id], ['build_mode' => 'on-server']))->assertSessionHasNoErrors();

    expect(Site::query()->firstOrFail()->build_mode)->toBe(BuildMode::OnServer);
});

it('makes slugs unique and keeps a manual deploy key for custom git', function () {
    $server = sites_server($this->organization->id);
    $custom = $this->git->addConnection($this->organization->id, ProviderType::Custom);

    $this->post('/sites', sites_input([$server->id], ['name' => 'Shop']));
    $this->post('/sites', sites_input([$server->id], ['name' => 'Shop.', 'source_connection_id' => $custom->id, 'repository' => 'git@git.example.com:acme/shop.git', 'branch' => 'main']))->assertSessionHasNoErrors();

    $site = Site::query()->where('name', 'Shop.')->firstOrFail();
    expect($site->slug)->toBe('shop-2');

    $this->get("/sites/{$site->id}")->assertInertia(fn ($page) => $page
        ->component('Sites/Show', false)
        ->where('details.deploy_key.installed', false)
        ->where('details.connection.provider', 'custom'));
});

it('surfaces source control failures as warnings without blocking creation', function () {
    $server = sites_server($this->organization->id);
    $connection = $this->git->addConnection($this->organization->id);
    $this->git->failKeysWith = 'Bad credentials';

    $response = $this->post('/sites', sites_input([$server->id], ['source_connection_id' => $connection->id, 'repository' => 'acme/shop', 'branch' => 'main']));

    $response->assertSessionHas('sites.warnings', fn (array $warnings) => str_contains($warnings[0], 'Bad credentials'));
    expect(Site::query()->count())->toBe(1);
});

it('forbids viewers from creating sites', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $server = sites_server($this->organization->id);

    $this->actingAs($viewer)->post('/sites', sites_input([$server->id]))->assertForbidden();
    $this->actingAs($viewer)->get('/sites/create')->assertForbidden();
});
