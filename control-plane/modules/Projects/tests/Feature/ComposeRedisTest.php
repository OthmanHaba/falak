<?php

use Illuminate\Validation\ValidationException;
use Kiln\Databases\Application\Actions\EnableContainerAccess;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Contracts\DatabaseProvisioner;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Projects\Contracts\VariableReferences;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Application\Compose\KilnAdjustments;
use Kiln\Sites\Contracts\ComposeServiceExtraction;
use Kiln\Sites\Contracts\SiteFactory;
use Kiln\Sites\Domain\Models\Site;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Compose apps' Redis / Valkey services as Kiln instances (v0.7.1, phase 4 of docs/plans/REDIS.md): the official
 * images only, an instance on the stack's server, the stack's references rewritten, the containers reaching it through
 * the Docker bridge.
 */

const CACHE_STACK = <<<'YAML'
services:
  app:
    build: ./app
    environment:
      REDIS_HOST: cache
      CACHE_URL: redis://cache:6379/1
      SESSION_DRIVER: redis
  worker:
    image: ghcr.io/acme/worker:1
    environment: ["BROKER=redis://:old-secret@cache:6379/0", "QUEUE_ADDR=cache:6379", "OTHER=mycache:6379"]
  cache:
    image: redis:7.4-alpine
    command: redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru
  sessions:
    image: docker.io/valkey/valkey:8
    command: ["valkey-server", "--maxmemory", "1gb"]
  stack:
    image: redis/redis-stack:latest
  bitnami:
    image: bitnami/redis:7.2
YAML;

/** An app server running PostgreSQL, $cache and Docker, with an agent that has Redis network access. */
function compose_redis_server(object $test, ?string $cache = 'redis', array $attributes = []): Server
{
    $server = databases_server($test->organization, 'postgresql', attributes: ['stack' => array_filter(['database' => 'postgresql', 'cache' => $cache, 'docker' => true]), ...$attributes]);
    Agent::factory()->create(['server_id' => $server->id, 'organization_id' => $test->organization->id, 'facts' => ['features' => ['db.redis', 'db.containers', 'db.redis.network'], 'memory_bytes' => 4 * 1024 ** 3]]);
    app(EngineInventory::class)->syncOrganization($test->organization->id);
    app(EnableContainerAccess::class)($server->id);

    return $server;
}

function compose_redis_stack(object $test, Server $server, string $name = 'Shop'): Site
{
    return projects_site($test->organization, $name, [], $test->environment, [$server], [
        'runtime' => 'compose', 'framework' => 'docker', 'php_version' => null, 'compose_source' => 'repo', 'compose_file' => 'compose.yaml',
        'source_connection_id' => null, 'repository' => 'acme/shop', 'branch' => 'main',
    ]);
}

function compose_redis_fails(callable $call): string
{
    try {
        $call();
    } catch (ValidationException $e) {
        return (string) collect($e->errors())->flatten()->first();
    }

    throw new RuntimeException('expected a validation error');
}

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->user, $this->organization] = memberOf();
    $this->actingAs($this->user);
    $this->environment = projects_default_env($this->organization);
    $this->extraction = app(ComposeServiceExtraction::class);
});

it('replaces an official redis service with a Kiln Redis on the stack server, its flags kept and its references rewritten', function () {
    $server = compose_redis_server($this);
    $stack = compose_redis_stack($this, $server);

    $instance = $this->extraction->toDatabase($stack->id, 'cache', null, 'redis', CACHE_STACK);

    $apply = $this->agents->last('db.redis.apply');
    expect($instance->engine)->toBe('redis')
        ->and($instance->name)->toBe("{$stack->slug}-cache")
        ->and($instance->serverId)->toBe($server->id)
        ->and($apply['payload'])->toMatchArray(['name' => "{$stack->slug}-cache", 'maxmemory_mb' => 256, 'eviction' => 'allkeys-lru', 'persistence' => 'aof', 'containers' => true])
        ->and($stack->refresh()->compose_services['cache'])->toMatchArray(['mode' => 'database', 'database_id' => $instance->id])
        ->and(projects_service('database', $instance->id)?->environment_id)->toBe($this->environment->id);

    $groups = $this->extraction->rewrites($stack->id)->groups;
    expect($groups)->toBe([
        'app' => [
            'CACHE_URL' => '${{ Shop cache.REDIS_URL }}/1',
            'REDIS_HOST' => '${{ Shop cache.REDIS_HOST }}',
            // The instance listens on 6380+ and always has a password: both join REDIS_HOST.
            'REDIS_PASSWORD' => '${{ Shop cache.REDIS_PASSWORD }}',
            'REDIS_PORT' => '${{ Shop cache.REDIS_PORT }}',
        ],
        'worker' => [
            'BROKER' => '${{ Shop cache.REDIS_URL }}/0',
            'QUEUE_ADDR' => '${{ Shop cache.REDIS_HOST }}:${{ Shop cache.REDIS_PORT }}',
        ],
    ]);

    // The stack's containers reach the instance through the Docker bridge, once the agent listens there.
    $dotenv = $this->extraction->rewrites($stack->id)->dotenv();
    $resolved = app(VariableReferences::class)->resolve($this->environment->id, $stack->id, $dotenv);
    expect(implode(' ', $resolved->errors))->toContain('does not listen on the Docker bridge (docker0) yet');

    $this->agents->succeed($apply['handle'], ['changed' => true, 'restarted' => true, 'port' => $apply['payload']['port'], 'bind' => ['127.0.0.1', '172.17.0.1'], 'container_host' => '172.17.0.1']);
    $resolved = app(VariableReferences::class)->resolve($this->environment->id, $stack->id, $dotenv);
    $port = Database::query()->findOrFail($instance->id)->port;
    $password = $resolved->variables['KILN_SVC_APP_REDIS_PASSWORD'];
    expect($resolved->errors)->toBe([])
        ->and($resolved->variables['KILN_SVC_APP_REDIS_HOST'])->toBe('172.17.0.1')
        ->and($resolved->variables['KILN_SVC_APP_REDIS_PORT'])->toBe((string) $port)
        ->and($password)->toMatch('/^[A-Za-z0-9]{32}$/')
        ->and($resolved->variables['KILN_SVC_APP_CACHE_URL'])->toBe("redis://default:{$password}@172.17.0.1:{$port}/1")
        ->and($resolved->variables['KILN_SVC_WORKER_QUEUE_ADDR'])->toBe("172.17.0.1:{$port}");

    // Rendering: the service is gone, the remaining services read the rewritten variables (REDIS_PASSWORD added).
    $doc = KilnAdjustments::apply(Yaml::parse(CACHE_STACK), $stack->refresh()->composeConfig(), null, $this->extraction->rewrites($stack->id))['doc'];
    expect($doc['services'])->not->toHaveKey('cache')
        ->and($doc['services']['app']['environment'])->toMatchArray(['REDIS_HOST' => '${KILN_SVC_APP_REDIS_HOST}', 'REDIS_PORT' => '${KILN_SVC_APP_REDIS_PORT}', 'REDIS_PASSWORD' => '${KILN_SVC_APP_REDIS_PASSWORD}', 'SESSION_DRIVER' => 'redis'])
        ->and($doc['services']['worker']['environment'])->toBe(['BROKER=${KILN_SVC_WORKER_BROKER}', 'QUEUE_ADDR=${KILN_SVC_WORKER_QUEUE_ADDR}', 'OTHER=mycache:6379']);
});

it('creates a Kiln Valkey from valkey/valkey where the server runs Valkey', function () {
    $server = compose_redis_server($this, 'valkey', ['os' => 'ubuntu 26.04']);
    $stack = compose_redis_stack($this, $server);

    $instance = $this->extraction->toDatabase($stack->id, 'sessions', null, 'valkey', CACHE_STACK);

    expect($instance->engine)->toBe('valkey')
        ->and($this->agents->last('db.redis.apply')['payload'])->toMatchArray(['engine' => 'valkey', 'maxmemory_mb' => 1024, 'persistence' => 'rdb', 'eviction' => 'noeviction']);
});

it('keeps redis-stack, bitnami/redis and engine mismatches in the stack with a reason', function () {
    $server = compose_redis_server($this);
    $stack = compose_redis_stack($this, $server);

    expect(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'stack', null, 'redis', CACHE_STACK)))->toBe('Service stack runs redis/redis-stack:latest, not the official Redis image.')
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'bitnami', null, 'redis', CACHE_STACK)))->toContain('not the official Redis image')
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'cache', null, 'valkey', CACHE_STACK)))->toContain('not the official Valkey image')
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'app', null, 'redis', CACHE_STACK)))->toContain('has no image')
        // The server runs Redis: a valkey service can't become an instance there (one cache engine per server).
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($stack->id, 'sessions', null, 'valkey', CACHE_STACK)))->toContain("runs Valkey, but {$server->name} runs Redis (one cache engine per server)");

    $this->agents->assertNothingDispatched('db.redis.apply');
    expect($stack->refresh()->compose_services)->toBeNull();

    // No cache engine yet: install it first; Valkey where the OS has none: say so.
    $bare = compose_redis_server($this, null, ['os' => 'ubuntu 22.04']);
    $other = compose_redis_stack($this, $bare, 'Blog');
    expect(compose_redis_fails(fn () => $this->extraction->toDatabase($other->id, 'cache', null, 'redis', CACHE_STACK)))->toContain("{$bare->name} doesn't run Redis yet: install it first")
        ->and(compose_redis_fails(fn () => $this->extraction->toDatabase($other->id, 'sessions', null, 'valkey', CACHE_STACK)))->toContain("Valkey isn't available for {$bare->name}'s operating system");
});

it('names the instance apart from one the server has, and keeps defaults when the command sets nothing it understands', function () {
    $server = compose_redis_server($this);
    $stack = compose_redis_stack($this, $server);
    $taken = app(DatabaseProvisioner::class)->create($this->organization->id, $server->id, 'redis', "{$stack->slug}-cache");

    $yaml = str_replace('command: redis-server --appendonly yes --maxmemory 256mb --maxmemory-policy allkeys-lru', 'command: ["sh", "-c", "redis-server --maxmemory $$MEM"]', CACHE_STACK);
    $instance = $this->extraction->toDatabase($stack->id, 'cache', null, 'redis', $yaml);

    expect($instance->name)->toBe("{$stack->slug}-cache-2")->and($instance->port)->not->toBe($taken->port)
        ->and($this->agents->last('db.redis.apply')['payload'])->toMatchArray(['maxmemory_mb' => 128, 'eviction' => 'noeviction', 'persistence' => 'rdb']);
});

it('takes the redis service of an inline stack out at creation (API users, plain git servers)', function () {
    $server = compose_redis_server($this);
    $yaml = <<<'YAML'
services:
  probe:
    image: redis:7.4.1-alpine
    environment: {REDIS_HOST: cache}
  cache:
    image: redis:7.4.1-alpine
    command: ["redis-server", "--maxmemory", "64mb"]
YAML;

    $created = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'probe', 'runtime' => 'compose', 'server_ids' => [$server->id], 'compose_source' => 'inline', 'compose_content' => $yaml,
        'compose_services' => ['cache' => ['mode' => 'database', 'engine' => 'redis']],
    ]);

    $decision = $created->site->compose?->mode('cache');
    $instance = Database::query()->where('server_id', $server->id)->where('name', "{$created->site->slug}-cache")->first();
    expect($created->warnings)->toBe([])->and($decision)->toBe('database')->and($instance)->not->toBeNull()
        ->and($this->agents->last('db.redis.apply')['payload'])->toMatchArray(['maxmemory_mb' => 64, 'containers' => true])
        ->and($this->extraction->rewrites($created->site->id)->forService('probe'))->toHaveKeys(['REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD']);
});
