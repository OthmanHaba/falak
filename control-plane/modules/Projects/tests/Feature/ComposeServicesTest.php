<?php

use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Contracts\Data\DatabaseData;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Servers\Domain\Models\Server;
use Falak\Sites\Application\ComposeSettings;
use Falak\Sites\Contracts\ComposeServiceExtraction;
use Falak\Sites\Contracts\ComposeSites;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\ComposeServiceExtracted;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

// The merged project of deploy/compose.yaml (as verifyRepository() returns it): paths are repository-relative, so
// `context: ../api` in deploy/compose.yaml is ./api here.
const SHOP_STACK = <<<'YAML'
services:
  app:
    build:
      context: ./api
      dockerfile: Dockerfile.prod
    ports: ["8000:8000"]
    environment:
      DATABASE_URL: postgres://shop:secret@db:5432/shop
      DB_HOST: db
      DB_PASSWORD: secret
      REDIS_URL: redis://cache:6379
      API_KEY: ${API_KEY}
      MODE: ${MODE:-production}
  worker:
    image: ghcr.io/acme/worker:1
    environment: ["UPSTREAM=http://app:8000/v1", "PGHOST=db", "PGPORT=5432"]
  db:
    image: postgres:17
    environment: { POSTGRES_DB: shop, POSTGRES_PASSWORD: secret }
  cache:
    image: redis:7
YAML;

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = memberOf();
    $this->actingAs($this->user);
    $this->environment = projects_default_env($this->organization);

    // An app server running PostgreSQL and Docker; the stack runs there.
    $this->server = databases_server($this->organization, 'postgresql', attributes: ['stack' => ['database' => 'postgresql', 'docker' => true]]);
    $this->engine = app(EngineInventory::class)->sync($this->server->id);
    $this->stack = projects_site($this->organization, 'Shop', ['API_KEY' => 'k-123'], $this->environment, [$this->server], [
        'runtime' => 'compose', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'repo', 'compose_file' => 'deploy/compose.yaml',
        'source_connection_id' => null, 'repository' => 'acme/shop', 'branch' => 'main',
    ]);
    $this->extraction = app(ComposeServiceExtraction::class);
});

it('replaces a database service with a Falak database next to the stack and rewrites what pointed at it', function () {
    $database = $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK);

    expect($database->name)->toBe('shop')
        ->and($database->serverId)->toBe($this->server->id)
        ->and($this->agents->last('db.create')['payload']['name'])->toBe('shop')
        ->and($this->stack->refresh()->compose_services['db'])->toMatchArray(['mode' => 'database', 'database_id' => $database->id]);

    // Placed in the stack's environment, so its references resolve there.
    expect(projects_service('database', $database->id)?->environment_id)->toBe($this->environment->id);

    $rewrites = $this->extraction->rewrites($this->stack->id);
    expect($rewrites->groups)->toBe([
        'app' => [
            'DATABASE_URL' => '${{ Shop db.DATABASE_URL }}',
            'DB_HOST' => '${{ Shop db.DB_HOST }}',
            'DB_PASSWORD' => '${{ Shop db.DB_PASSWORD }}',
        ],
        'worker' => [
            'PGHOST' => '${{ Shop db.DB_HOST }}',
            'PGPORT' => '${{ Shop db.DB_PORT }}',
        ],
    ]);
    $rewrites = $rewrites->dotenv();

    // Resolved for the stack (containers on the engine's server): the Docker bridge, once container access is on.
    $this->engine->forceFill(['container_access' => true])->save();
    $resolved = app(VariableReferences::class)->resolve($this->environment->id, $this->stack->id, $rewrites);
    expect($resolved->errors)->toBe([])
        ->and(array_keys($rewrites))->toBe(['FALAK_SVC_APP_DATABASE_URL', 'FALAK_SVC_APP_DB_HOST', 'FALAK_SVC_APP_DB_PASSWORD', 'FALAK_SVC_WORKER_PGHOST', 'FALAK_SVC_WORKER_PGPORT'])
        ->and($resolved->variables['FALAK_SVC_APP_DB_HOST'])->toBe('172.17.0.1')
        ->and($resolved->variables['FALAK_SVC_APP_DATABASE_URL'])->toStartWith('postgresql://shop:');
});

it('picks a free database name when another stack\'s database holds the one the service used', function () {
    $taken = app(DatabaseProvisioner::class)->create($this->organization->id, $this->server->id, 'postgresql', 'shop');
    app(DatabaseProvisioner::class)->create($this->organization->id, $this->server->id, 'postgresql', str_replace('-', '_', $this->stack->slug).'_shop');

    $database = $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK);

    // shop is taken, <stack>_shop too: <stack>_shop_2. The app reads the name from its rewritten DATABASE_URL.
    expect($database->id)->not->toBe($taken->id)
        ->and($database->name)->toBe(str_replace('-', '_', $this->stack->slug).'_shop_2')
        ->and($this->stack->refresh()->compose_services['db'])->toMatchArray(['mode' => 'database', 'database_id' => $database->id])
        ->and($this->extraction->rewrites($this->stack->id)->groups['app']['DATABASE_URL'])->toStartWith('${{ ');
});

it('links an existing database of the same engine and environment instead of creating one', function () {
    [$existing] = projects_database($this->organization, 'orders', $this->environment, engineServer: $this->engine);

    $database = $this->extraction->toDatabase($this->stack->id, 'db', $existing->id, 'postgresql', SHOP_STACK);

    expect($database->id)->toBe($existing->id)
        ->and($this->agents->dispatched('db.create'))->toBe([])
        ->and($this->extraction->rewrites($this->stack->id)->forService('app')['DATABASE_URL'])->toBe('${{ orders.DATABASE_URL }}');
});

it('refuses services that are not a database of that engine, and services already taken out', function () {
    $fails = function (callable $call, string $key) {
        try {
            $call();
        } catch (ValidationException $e) {
            return expect($e->errors())->toHaveKey($key);
        }
        throw new RuntimeException('expected a validation error');
    };

    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'cache', null, 'postgresql', SHOP_STACK), 'engine');
    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'cache', null, 'redis', SHOP_STACK), 'engine');
    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'nope', null, 'postgresql', SHOP_STACK), 'service');

    $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK);
    $fails(fn () => $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK), 'service');
});

it('runs an app service as its own Falak site from its build context, with its variables, next to the stack', function () {
    $this->stack->forceFill([
        'public_services' => [['service' => 'app', 'port' => 8000, 'host_port' => 20001], ['service' => 'worker', 'port' => 9000, 'host_port' => 20002]],
        'app_port' => 20001,
    ])->save();
    $extracted = [];
    Event::listen(ComposeServiceExtracted::class, function (ComposeServiceExtracted $event) use (&$extracted) {
        $extracted[] = $event;
    });

    $site = $this->extraction->toSite($this->stack->id, 'app', ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker'], SHOP_STACK);
    $model = Site::query()->find($site->id);

    // The stack stops serving it: its public entry goes, the next public service becomes the primary.
    expect($this->stack->refresh()->public_services)->toBe([['service' => 'worker', 'port' => 9000, 'host_port' => 20002]])
        ->and($this->stack->app_port)->toBe(20002)
        ->and($extracted)->toHaveCount(1)
        ->and($extracted[0]->kind)->toBe('site')
        ->and($extracted[0]->service)->toBe('app')
        ->and($extracted[0]->refId)->toBe($site->id);

    // The merged project's build context is repository-relative: ./api is the repository folder api.
    expect($site->rootDirectory)->toBe('api')
        ->and($site->dockerfile)->toBe('Dockerfile.prod')
        ->and($site->containerPort)->toBe(8000)
        ->and($site->repository)->toBe('acme/shop')
        ->and($site->branch)->toBe('main')
        ->and($site->serverIds())->toBe([$this->server->id])
        ->and($model->environmentVersions()->first()->variables)->toMatchArray(['API_KEY' => 'k-123', 'MODE' => 'production', 'DB_HOST' => 'db'])
        ->and(projects_service('site', $site->id)?->environment_id)->toBe($this->environment->id)
        ->and($this->stack->refresh()->compose_services['app'])->toMatchArray(['mode' => 'site', 'site_id' => $site->id]);

    // UPSTREAM pointed at the service: it becomes the site's address (here its test domain).
    $model->forceFill(['test_domain_enabled' => true])->save();
    config(['sites.test_domain' => 'falak.test']);
    expect($this->extraction->rewrites($this->stack->id)->groups)->toBe(['worker' => ['UPSTREAM' => 'https://'.$model->refresh()->testDomain().'/v1']]);
});

it('lets a split-out Docker service join the stack networks on the stack servers, and warns native ones', function () {
    $site = $this->extraction->toSite($this->stack->id, 'app', ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker'], SHOP_STACK);

    $network = $this->stack->slug.'_default';
    // The stack's project owns its default network: an agent creates it with Compose's labels when the site deploys first.
    $owned = ['project' => $this->stack->slug, 'network' => 'default'];
    expect($this->stack->refresh()->compose_services['app'])->toMatchArray(['networks' => [$network], 'compose_networks' => [$network => 'default'], 'uses' => ['cache', 'db']])
        ->and(app(ComposeSites::class)->stackNetworks($site->id, $this->server->id))->toBe([['name' => $network, 'aliases' => ['app'], 'compose' => $owned]])
        // A server the stack doesn't run on: nothing to join there.
        ->and(app(ComposeSites::class)->stackNetworks($site->id, '01j9zq4n8v2m6r0t3w5y7b9d1f'))->toBe([])
        // Not split out of a stack.
        ->and(app(ComposeSites::class)->stackNetworks($this->stack->id, $this->server->id))->toBe([]);

    // Decisions recorded before networks were: the stack's default network.
    $services = $this->stack->compose_services;
    unset($services['app']['networks'], $services['app']['compose_networks']);
    $this->stack->forceFill(['compose_services' => $services])->save();
    expect(app(ComposeSites::class)->stackNetworks($site->id, $this->server->id))->toBe([['name' => $network, 'aliases' => ['app'], 'compose' => $owned]]);

    // The stack is gone: the site runs on its own network only.
    $this->stack->delete();
    expect(app(ComposeSites::class)->stackNetworks($site->id, $this->server->id))->toBe([]);
});

it('joins no stack network for a network_mode service, and keeps declared aliases and interpolated names', function () {
    $yaml = <<<'YAML'
services:
  app:
    image: api
    networks:
      back: { aliases: [api, 'bad alias'] }
      default: {}
    environment: { CACHE_HOST: '${CACHE}' }
  sidecar:
    image: tool
    network_mode: none
  cache:
    image: redis:7
networks:
  back: { name: '${BACK_NETWORK}' }
YAML;
    $this->stack->environmentVersions()->first()->forceFill(['variables' => ['BACK_NETWORK' => 'shop-back', 'CACHE' => 'cache']])->save();

    $app = $this->extraction->toSite($this->stack->id, 'app', ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker'], $yaml);
    $sidecar = $this->extraction->toSite($this->stack->id, 'sidecar', ['name' => 'Tool', 'framework' => 'docker', 'runtime' => 'docker'], $yaml);

    $default = $this->stack->slug.'_default';
    expect($this->stack->refresh()->compose_services['app'])->toMatchArray([
        'networks' => ['shop-back', $default],
        'network_aliases' => ['shop-back' => ['api', 'bad alias']],
        'uses' => ['cache'],
    ])
        // A `name:` override is still the project's network (Compose creates and labels it as `back`).
        ->and(app(ComposeSites::class)->stackNetworks($app->id, $this->server->id))->toBe([
            ['name' => 'shop-back', 'aliases' => ['app', 'api'], 'compose' => ['project' => $this->stack->slug, 'network' => 'back']],
            ['name' => $default, 'aliases' => ['app'], 'compose' => ['project' => $this->stack->slug, 'network' => 'default']],
        ])
        // network_mode: none — on no stack network, so not on the default one either.
        ->and($this->stack->compose_services['sidecar']['networks'])->toBe([])
        ->and(app(ComposeSites::class)->stackNetworks($sidecar->id, $this->server->id))->toBe([]);
});

it('gives a split-out site the keys of its env files from the repository, under the stack root, environment winning', function () {
    $git = sites_fake_source_control();
    $connection = $git->addConnection($this->organization->id);
    $git->files = [
        'apps/shared.env' => "SHARED=yes\nPOOL_SIZE=1\n",
        'apps/shop/api/defaults.env' => "APP_NAME=shop-api\nPOOL_SIZE=5\nMODE=from-file\nFALAK_SITE_ID=nope\n",
        'apps/shop/api/local.env' => "POOL_SIZE=10\n",
        'apps/shop/.env' => "SECRET=never\n",
        'outside.env' => "OUTSIDE=yes\n",
    ];
    $this->stack->forceFill(['source_connection_id' => $connection->id, 'root_directory' => 'apps/shop'])->save();
    app()->forgetInstance(ComposeServiceExtraction::class);
    $this->extraction = app(ComposeServiceExtraction::class); // with the fake repository
    $yaml = <<<'YAML'
services:
  app:
    image: api
    env_file: [../shared.env, ./api/defaults.env, { path: ./api/local.env, required: false }, ./api/missing.env, .env, ../../../outside.env]
    environment: { MODE: '${MODE:-production}' }
YAML;

    $site = $this->extraction->toSite($this->stack->id, 'app', ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker'], $yaml);

    // Later env files win, `environment:` wins over them, FALAK_* keys and Falak's own .env are left out (PORT is the
    // Docker site's own).
    // ../shared.env above the root directory is still in the repository; ../../../outside.env leaves it.
    expect(Site::query()->find($site->id)->environmentVersions()->first()->variables)->toMatchArray([
        'SHARED' => 'yes',
        'APP_NAME' => 'shop-api',
        'POOL_SIZE' => '10',
        'MODE' => 'production',
    ])->not->toHaveKeys(['FALAK_SITE_ID', 'SECRET', 'OUTSIDE']);
});

it('only lets agents create plain project networks: configured ones and reserved names wait for the stack', function () {
    $yaml = <<<'YAML'
services:
  app:
    image: api
    networks: [default, private, named, falaknet]
networks:
  private: { internal: true }
  named: { name: shop-named }
  falaknet: { name: falak-internal }
YAML;
    $warnings = app(ComposeSettings::class)->extract(Site::query()->findOrFail($this->stack->id), [
        ['service' => 'app', 'mode' => 'site', 'site' => ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker']],
    ], $yaml);
    $decision = $this->stack->refresh()->compose_services['app'];
    $slug = $this->stack->slug;

    // Compose v2 reuses a labelled network without checking its configuration: `internal: true` must come from Compose.
    expect($decision['compose_networks'])->toBe(["{$slug}_default" => 'default', 'shop-named' => 'named'])
        ->and($decision['waited_networks'])->toBe(["{$slug}_private", 'falak-internal'])
        ->and(implode("\n", $warnings))->toContain("{$slug}_private, falak-internal, which only the stack's own deploy can create")
        ->and(collect(app(ComposeSites::class)->stackNetworks($decision['site_id'], $this->server->id))->mapWithKeys(fn ($n) => [$n['name'] => isset($n['compose'])])->all())
        ->toBe(["{$slug}_default" => true, "{$slug}_private" => false, 'shop-named' => true, 'falak-internal' => false]);
});

it('never asks agents to create an external network a split-out service joins', function () {
    $yaml = <<<'YAML'
services:
  app:
    image: api
    networks: [shared, default]
networks:
  shared: { external: true }
YAML;
    $app = $this->extraction->toSite($this->stack->id, 'app', ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker'], $yaml);

    expect(app(ComposeSites::class)->stackNetworks($app->id, $this->server->id))->toBe([
        ['name' => 'shared', 'aliases' => ['app']],
        ['name' => $this->stack->slug.'_default', 'aliases' => ['app'], 'compose' => ['project' => $this->stack->slug, 'network' => 'default']],
    ]);
});

it('warns when a split-out service runs natively and can no longer reach the stack services it uses', function () {
    $warnings = app(ComposeSettings::class)->extract(Site::query()->findOrFail($this->stack->id), [
        ['service' => 'app', 'mode' => 'site', 'site' => ['name' => 'API', 'framework' => 'node']],
    ], SHOP_STACK);

    expect($warnings)->toBe(['app uses cache, db inside the stack; a native site can only reach public services — pick Docker, or make them public.']);
});

it('warns once at extraction about stack networks the agent can’t join, and leaves them out of every deploy', function () {
    $networks = implode("\n", array_map(fn (int $i) => "  n{$i}: {}", range(1, 9)));
    $list = implode(', ', array_map(fn (int $i) => "n{$i}", range(1, 9)));
    $yaml = "services:\n  app:\n    image: api\n    networks: [{$list}, odd]\nnetworks:\n{$networks}\n  odd: { name: '-odd' }\n";

    $warnings = app(ComposeSettings::class)->extract(Site::query()->findOrFail($this->stack->id), [
        ['service' => 'app', 'mode' => 'site', 'site' => ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker']],
    ], $yaml);

    $slug = $this->stack->slug;
    expect($warnings)->toBe(["app doesn't join the stack network(s) -odd, {$slug}_n9: a container joins at most 8 networks, named with letters, digits and _ . - (starting with a letter or digit)."]);
    $decision = $this->stack->refresh()->compose_services['app'];
    expect($decision['networks'])->toHaveCount(8)
        ->and($decision['skipped_networks'])->toBe(['-odd', "{$slug}_n9"]);

    // A decision recorded before the check: the deploy payload still only has what the agent accepts.
    $decision['networks'] = [...$decision['networks'], 'bad name', "{$slug}_n9"];
    $this->stack->forceFill(['compose_services' => ['app' => $decision]])->save();
    $joins = app(ComposeSites::class)->stackNetworks((string) $decision['site_id'], $this->server->id);
    expect(array_column($joins, 'name'))->toBe(array_map(fn (int $i) => "{$slug}_n{$i}", range(1, 8)));
});

it('refuses build contexts outside the repository', function () {
    $yaml = "services:\n  app:\n    build: ../outside\n";

    expect(fn () => $this->extraction->toSite($this->stack->id, 'app', ['framework' => 'docker'], $yaml))->toThrow(ValidationException::class);
});

it('keeps the same variable name pointing at different databases apart, per service', function () {
    $yaml = <<<'YAML'
services:
  api:
    image: acme/api
    environment: {DB_HOST: orders-db, DB_PASSWORD: a}
  reports:
    image: acme/reports
    environment: {DB_HOST: stats-db, DB_PASSWORD: b}
  orders-db:
    image: postgres:17
  stats-db:
    image: postgres:17
YAML;
    $this->extraction->toDatabase($this->stack->id, 'orders-db', null, 'postgresql', $yaml);
    $this->extraction->toDatabase($this->stack->id, 'stats-db', null, 'postgresql', $yaml);

    expect($this->extraction->rewrites($this->stack->id)->groups)->toBe([
        'api' => ['DB_HOST' => '${{ Shop orders-db.DB_HOST }}', 'DB_PASSWORD' => '${{ Shop orders-db.DB_PASSWORD }}'],
        'reports' => ['DB_HOST' => '${{ Shop stats-db.DB_HOST }}', 'DB_PASSWORD' => '${{ Shop stats-db.DB_PASSWORD }}'],
    ]);
});

it('gives the service back when creating the Falak service fails, and refuses a second claim', function () {
    app()->instance(DatabaseProvisioner::class, new class implements DatabaseProvisioner
    {
        public function create(string $organizationId, string $serverId, string $engine, string $name, ?string $actorId = null, array $options = []): DatabaseData
        {
            throw ValidationException::withMessages(['name' => 'boom']);
        }

        public function delete(string $databaseId): void {}
    });
    app()->forgetInstance(ComposeServiceExtraction::class);
    $extraction = app(ComposeServiceExtraction::class);

    expect(fn () => $extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK))->toThrow(ValidationException::class);
    expect($this->stack->refresh()->compose_services)->toBeNull();

    // A claim in progress (another request) blocks a second one, and does not drop the service from the stack yet.
    $this->stack->forceFill(['compose_services' => ['db' => ['mode' => 'pending']]])->save();
    expect(fn () => $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK))->toThrow(ValidationException::class)
        ->and($this->stack->refresh()->toData()->compose->extracted())->toBe([]);
});

it('only extracts services for members allowed to create the Falak service (create flow and Settings → Compose)', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $warnings = app(ComposeSettings::class)->extract($this->stack, [
        ['service' => 'db', 'mode' => 'database', 'engine' => 'postgresql', 'database_id' => null, 'site' => []],
        ['service' => 'app', 'mode' => 'site', 'engine' => null, 'database_id' => null, 'site' => ['framework' => 'docker']],
    ], SHOP_STACK);

    expect($warnings)->toBe([
        "db stays in the stack: you don't have permission to create databases.",
        "app stays in the stack: you don't have permission to create sites.",
    ])->and($this->agents->dispatched('db.create'))->toBe([])
        ->and($this->stack->refresh()->compose_services)->toBeNull();

    // A developer may do both.
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);
    expect(app(ComposeSettings::class)->extract($this->stack, [['service' => 'db', 'mode' => 'database', 'engine' => 'postgresql', 'database_id' => null, 'site' => []]], SHOP_STACK))->toBe([])
        ->and($this->stack->refresh()->compose_services['db']['mode'])->toBe('database');
});

it('rewrites a split-out site\'s variables when a service it uses moves to a Falak database', function () {
    $site = $this->extraction->toSite($this->stack->id, 'app', ['name' => 'API', 'framework' => 'docker', 'runtime' => 'docker'], SHOP_STACK);
    $model = Site::query()->find($site->id);
    expect($model->environmentVersions()->orderByDesc('version')->first()->variables['DB_HOST'])->toBe('db');

    $this->extraction->toDatabase($this->stack->id, 'db', null, 'postgresql', SHOP_STACK);

    $host = $model->environmentVersions()->orderByDesc('version')->first()->variables['DB_HOST'];
    expect($host)->toStartWith('${{ ')->toEndWith('.DB_HOST }}')
        ->and($model->environmentVersions()->first()->variables)->toMatchArray(['API_KEY' => 'k-123', 'MODE' => 'production']);
});
