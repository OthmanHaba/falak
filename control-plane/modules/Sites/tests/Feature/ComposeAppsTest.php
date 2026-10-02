<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Kiln\Databases\Contracts\Data\DatabaseData;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Contracts\ComposeServiceExtraction;
use Kiln\Sites\Contracts\ComposeSites;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\Exceptions\ComposeRenderException;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\ComposeServicesUnpublished;
use Kiln\SourceControl\Contracts\ProviderType;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../Support/helpers.php';

/**
 * Compose apps from a repository (docs/plans/COMPOSE_APPS.md): the user names the compose files, Kiln reads them
 * through the provider API, lists services and variables, and adjusts the project at render time.
 */
const SHOP_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:1.27-alpine
    container_name: shop-web
    ports: ["8080:80"]
    volumes:
      - ./nginx.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on: [app]
  app:
    build: ./app
    env_file: .env
    environment:
      APP_KEY: ${APP_KEY}
      DATABASE_URL: postgres://shop:secret@db:5432/shop
      LOG_LEVEL: ${LOG_LEVEL:-info}
    volumes:
      - ./storage:/var/www/storage
    depends_on: [db]
  db:
    image: postgres:17.2
    volumes: [pg:/var/lib/postgresql/data]
  mailpit:
    image: axllent/mailpit
    profiles: [dev]
volumes:
  pg:
YAML;

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    sites_fake_agents();
    $this->git = sites_fake_source_control();
    $this->connection = $this->git->addConnection($this->organization->id);
    $this->git->files = [
        'docker/compose.yml' => SHOP_COMPOSE,
        'docker/nginx.conf' => "server { listen 80; }\n",
        'docker/app/Dockerfile' => "FROM php:8.4\n",
        'docker/compose.override.yml' => "services:\n  web:\n    image: nginx:1.28-alpine\n",
        'compose.yaml' => "services: {x: {image: busybox}}\n",
    ];
    config(['sites.test_domain' => 'kiln.test']);
    $this->server = sites_server($this->organization->id, ['name' => 'app-1'], docker: true);
});

function compose_app_input(object $test, array $overrides = []): array
{
    return [
        'name' => 'shop',
        'runtime' => 'compose',
        'server_ids' => [$test->server->id],
        'source_connection_id' => $test->connection->id,
        'repository' => 'acme/shop',
        'branch' => 'main',
        'compose_source' => 'repo',
        'compose_files' => ['docker/compose.yml', 'docker/compose.override.yml'],
        'public_services' => [['service' => 'web', 'port' => 80]],
        'variables' => ['APP_KEY' => 'base64:abc'],
        ...$overrides,
    ];
}

it('suggests the compose files of a repository', function () {
    $this->postJson('/sites/compose/candidates', ['source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main'])
        ->assertOk()
        ->assertJsonPath('data.files', ['compose.yaml', 'docker/compose.override.yml', 'docker/compose.yml']);
});

it('inspects a repository compose project: services, variables and adjustments', function () {
    $data = $this->postJson('/sites/compose/inspect', [
        'source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main',
        'compose_files' => ['docker/compose.yml', 'docker/compose.override.yml'],
        'public_services' => [['service' => 'web', 'port' => 80]],
    ])->assertOk()->json('data');

    $services = collect($data['services'])->keyBy('name');

    expect($services->keys()->all())->toBe(['web', 'app', 'db'])
        ->and($services['web']['image'])->toBe('nginx:1.28-alpine')
        ->and($services['web']['binds'][0])->toMatchArray(['source' => './docker/nginx.conf', 'in_repo' => true])
        ->and($services['app']['build_context'])->toBe('./docker/app')
        ->and($services['app']['binds'][0])->toMatchArray(['source' => './docker/storage', 'in_repo' => false, 'key' => 'app:./docker/storage'])
        ->and($services['app']['env_files'][0])->toBe(['path' => 'docker/.env', 'in_repo' => false])
        ->and($services['db']['database_engine'])->toBe('postgresql')
        ->and($services['app']['database_engine'])->toBeNull()
        ->and($data['files'])->toBe(['docker/compose.yml', 'docker/compose.override.yml'])
        ->and($data['missing'])->toBe(['docker/.env', 'docker/storage']);

    $variables = collect($data['variables'])->keyBy('name');
    expect($variables['APP_KEY'])->toMatchArray(['required' => true, 'default' => null, 'services' => ['app']])
        ->and($variables['LOG_LEVEL'])->toMatchArray(['required' => false, 'default' => 'info']);

    $kinds = collect($data['adjustments'])->pluck('kind')->all();
    expect($kinds)->toContain('container_name', 'bind_to_volume', 'env_file_missing', 'restart', 'repo_files')
        ->and(Yaml::parse($data['adjusted'])['services']['web']['volumes'])->toBe(['./repo/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro'])
        ->and(Yaml::parse($data['adjusted'])['services']['app']['volumes'])->toBe(['app-docker-storage:/var/www/storage'])
        ->and($data['warnings'])->toContain('Public service web has no healthcheck: Kiln can only check it through its domain.');
});

it('reports plain git servers and unknown connections', function () {
    $custom = $this->git->addConnection($this->organization->id, ProviderType::Custom, 'ssh');

    $this->postJson('/sites/compose/inspect', ['source_connection_id' => $custom->id, 'repository' => 'git@example.com:a/b.git', 'branch' => 'main'])
        ->assertOk()->assertJsonPath('data.no_api', true);

    $other = $this->git->addConnection(memberOf()[1]->id);
    $this->postJson('/sites/compose/inspect', ['source_connection_id' => $other->id, 'repository' => 'acme/shop', 'branch' => 'main'])
        ->assertUnprocessable()->assertJsonValidationErrors('source_connection_id');
});

it('creates a compose app from several files and refuses missing required variables', function () {
    expect(fn () => app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, ['variables' => []])))
        ->toThrow(ValidationException::class, 'APP_KEY');

    expect(fn () => app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, ['public_services' => [['service' => 'mailpit', 'port' => 8025]]])))
        ->toThrow(ValidationException::class, 'no service mailpit');

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'compose_profiles' => ['dev'],
        'compose_adjustments' => ['keep_binds' => ['app:./docker/storage']],
    ]));
    $compose = $created->site->compose;

    expect($compose->files)->toBe(['docker/compose.yml', 'docker/compose.override.yml'])
        ->and($compose->file)->toBe('docker/compose.yml')
        ->and($compose->profiles)->toBe(['dev'])
        ->and($compose->adjustments)->toBe(['keep_binds' => ['app:./docker/storage']]);
});

it('leaves services in the stack with a warning when extraction is unavailable', function () {
    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'compose_services' => ['db' => ['mode' => 'database', 'engine' => 'postgresql']],
    ]));

    expect($created->warnings)->toContain('db stays in the stack: Replacing a compose service with a Kiln database is not available yet.')
        ->and($created->site->compose->mode('db'))->toBe('keep');
});

it('extracts services through the extraction contract and drops them from the public list', function () {
    $extraction = new class implements ComposeServiceExtraction
    {
        public array $calls = [];

        public function toDatabase(string $siteId, string $service, ?string $databaseId, string $engine, ?string $compose = null): DatabaseData
        {
            $this->calls[] = [$service, $engine];
            $site = Site::query()->findOrFail($siteId);
            $site->forceFill(['compose_services' => [...(array) $site->compose_services, $service => ['mode' => 'database', 'database_id' => '01j9zq4n8v2m6r0t3w5y7b9d1f']]])->save();

            return new DatabaseData('01j9zq4n8v2m6r0t3w5y7b9d1f', $site->organization_id, $site->targets()->first()->server_id, 'shop', 'shop', 'postgresql', '17', 5432, 'active', $siteId);
        }

        public function toSite(string $siteId, string $service, array $site, ?string $compose = null): SiteData
        {
            throw new LogicException('not used');
        }

        public function rewrites(string $siteId): array
        {
            return ['DATABASE_URL' => '${{ shop-db.DATABASE_URL }}'];
        }
    };
    app()->instance(ComposeServiceExtraction::class, $extraction);

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'compose_services' => ['db' => ['mode' => 'database', 'engine' => 'postgresql']],
        'public_services' => [['service' => 'web', 'port' => 80], ['service' => 'db', 'port' => 5432]],
    ]));

    expect($extraction->calls)->toBe([['db', 'postgresql']])
        ->and($created->site->compose->extracted())->toBe(['db'])
        ->and(array_map(fn ($p) => $p->service, $created->site->compose->publicServices))->toBe(['web']);

    $project = Yaml::dump(Yaml::parse(SHOP_COMPOSE));
    $rendered = Yaml::parse(app(ComposeSites::class)->render($created->site->id, $project, ['app' => 'registry.kiln.test/kiln/shop/app@sha256:'.str_repeat('a', 64)], '01j9zq4n8v2m6r0t3w5y7b9d1f')->yaml);

    expect($rendered['services'])->not->toHaveKey('db')
        ->and($rendered['services']['app']['depends_on'] ?? [])->toBe([])
        ->and($rendered['services']['app']['environment']['DATABASE_URL'])->toBe('${DATABASE_URL}');
});

it('renders repository projects against the files shipped with the release', function () {
    $site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this))->site;
    $project = <<<'YAML'
services:
  web:
    image: nginx:1.28-alpine
    container_name: shop-web
    volumes: ["./docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro", "./docker/storage:/data"]
    env_file: [./docker/.env.example, ./docker/.env]
    healthcheck: {test: ["CMD", "true"]}
configs:
  site: {file: ./docker/site.conf}
YAML;

    expect(fn () => app(ComposeSites::class)->render($site->id, $project, [], '01j9zq4n8v2m6r0t3w5y7b9d1f', ['docker/nginx.conf', 'docker/.env.example']))
        ->toThrow(ComposeRenderException::class, 'configs.site: docker/site.conf is not in the repository');

    $rendered = Yaml::parse(app(ComposeSites::class)->render($site->id, $project, [], '01j9zq4n8v2m6r0t3w5y7b9d1f', ['docker/nginx.conf', 'docker/.env.example', 'docker/site.conf'])->yaml);
    $web = $rendered['services']['web'];

    expect($web)->not->toHaveKey('container_name')
        ->and($web['restart'])->toBe('unless-stopped')
        ->and($web['volumes'])->toBe(['./repo/docker/nginx.conf:/etc/nginx/conf.d/default.conf:ro', 'web-docker-storage:/data'])
        ->and($web['env_file'])->toBe(['./repo/docker/.env.example', '.env'])
        ->and($rendered['configs']['site']['file'])->toBe('./repo/docker/site.conf')
        ->and($rendered['volumes'])->toHaveKey('web-docker-storage');

    // Builders that don't merge projects (no shipped file list) keep the old behaviour.
    $legacy = Yaml::parse(app(ComposeSites::class)->render($site->id, "services:\n  web:\n    image: nginx\n    container_name: x\n    volumes: [./conf:/c]\n", [], '01j9zq4n8v2m6r0t3w5y7b9d1f')->yaml);
    expect($legacy['services']['web']['container_name'])->toBe('x')
        ->and($legacy['services']['web']['volumes'])->toBe(['./conf:/c']);
});

it('saves compose project settings and reports services that are no longer public', function () {
    Event::fake([ComposeServicesUnpublished::class]);
    $site = Site::query()->findOrFail(app(SiteFactory::class)->create($this->organization->id, $this->user->id, compose_app_input($this, [
        'public_services' => [['service' => 'web', 'port' => 80], ['service' => 'app', 'port' => 9000]],
    ]))->site->id);

    $this->putJson("/sites/{$site->id}/compose", [
        'compose_source' => 'repo',
        'compose_files' => ['docker/compose.yml'],
        'compose_profiles' => [],
        'public_services' => [['service' => 'web', 'port' => 80]],
    ])->assertOk();

    expect($site->refresh()->composeFiles())->toBe(['docker/compose.yml']);
    Event::assertDispatched(ComposeServicesUnpublished::class, fn ($e) => $e->siteId === $site->id && $e->services === ['app']);

    $this->postJson("/sites/{$site->id}/compose/inspect", ['compose_profiles' => ['dev']])
        ->assertOk()
        ->assertJsonPath('data.services.3.name', 'mailpit');
});
