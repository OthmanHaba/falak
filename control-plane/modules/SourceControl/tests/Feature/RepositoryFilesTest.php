<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kiln\Identity\Contracts\Role;
use Kiln\SourceControl\Contracts\Exceptions\NoApi;
use Kiln\SourceControl\Contracts\Exceptions\SourceControlException;
use Kiln\SourceControl\Contracts\ProviderType;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use Kiln\SourceControl\Infrastructure\EloquentSourceControlGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [, $this->organization] = memberOf(null, Role::Owner);
    $this->gateway = app(SourceControlGateway::class);
});

it('reads a file from GitHub at a ref', function () {
    Http::fake(['api.github.com/repos/acme/shop/contents/docker/compose.prod.yml*' => Http::response([
        'type' => 'file', 'size' => 24, 'encoding' => 'base64', 'content' => chunk_split(base64_encode("services:\n  web: {}\n"), 8, "\n"),
    ])]);
    $connection = sc_connection($this->organization->id);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'docker/compose.prod.yml'))->toBe("services:\n  web: {}\n");
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ref=main'));
});

it('answers null for a missing path, a directory or a path leaving the repository', function () {
    Http::fake([
        'api.github.com/repos/acme/shop/contents/missing.yml*' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/repos/acme/shop/contents/docker*' => Http::response([['type' => 'file', 'path' => 'docker/a.yml']]),
    ]);
    $connection = sc_connection($this->organization->id);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'missing.yml'))->toBeNull()
        ->and($this->gateway->file($connection->id, 'acme/shop', 'main', 'docker'))->toBeNull()
        ->and($this->gateway->file($connection->id, 'acme/shop', 'main', '../etc/passwd'))->toBeNull();
});

it('refuses files larger than the limit', function () {
    Http::fake(['api.github.com/*' => Http::response(['type' => 'file', 'size' => SourceControlGateway::MAX_FILE_BYTES + 1, 'content' => ''])]);
    $connection = sc_connection($this->organization->id);

    $this->gateway->file($connection->id, 'acme/shop', 'main', 'big.yml');
})->throws(SourceControlException::class, 'larger than');

it('lists repository files matching a glob', function () {
    Http::fake(['api.github.com/repos/acme/shop/git/trees/main*' => Http::response(['tree' => [
        ['path' => 'compose.yaml', 'type' => 'blob'],
        ['path' => 'docker', 'type' => 'tree'],
        ['path' => 'docker/docker-compose.prod.yml', 'type' => 'blob'],
        ['path' => 'src/app.ts', 'type' => 'blob'],
    ]])]);
    $connection = sc_connection($this->organization->id);

    expect($this->gateway->tree($connection->id, 'acme/shop', 'main', '*compose*.y*ml'))->toBe(['compose.yaml', 'docker/docker-compose.prod.yml'])
        ->and($this->gateway->tree($connection->id, 'acme/shop', 'main', 'docker/**'))->toBe(['docker/docker-compose.prod.yml']);
});

it('reads files and trees from GitLab', function () {
    Http::fake([
        'gitlab.com/api/v4/projects/acme%2Fshop/repository/files/compose.yaml*' => Http::response(['size' => 9, 'content' => base64_encode('services:')]),
        'gitlab.com/api/v4/projects/acme%2Fshop/repository/tree*' => Http::response([
            ['path' => 'compose.yaml', 'type' => 'blob'], ['path' => 'lib', 'type' => 'tree'],
        ]),
    ]);
    $connection = sc_connection($this->organization->id, ProviderType::GitLab);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'compose.yaml'))->toBe('services:')
        ->and($this->gateway->tree($connection->id, 'acme/shop', 'main'))->toBe(['compose.yaml']);
});

it('reads files and trees from Bitbucket', function () {
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/compose.yaml' => Http::response('services:', 200, ['Content-Type' => 'text/plain']),
        'api.bitbucket.org/2.0/repositories/acme/shop/src/main/*' => Http::response(['values' => [
            ['path' => 'compose.yaml', 'type' => 'commit_file'], ['path' => 'lib', 'type' => 'commit_directory'],
        ]]),
    ]);
    $connection = sc_connection($this->organization->id, ProviderType::Bitbucket);

    expect($this->gateway->file($connection->id, 'acme/shop', 'main', 'compose.yaml'))->toBe('services:')
        ->and($this->gateway->tree($connection->id, 'acme/shop', 'main'))->toBe(['compose.yaml']);
});

it('reports that plain git servers have no file API', function () {
    $connection = sc_connection($this->organization->id, ProviderType::Custom, 'ssh', []);

    $this->gateway->file($connection->id, 'git@git.example.com:acme/shop.git', 'main', 'compose.yaml');
})->throws(NoApi::class);

it('translates globs', function (string $glob, string $path, bool $match) {
    expect(preg_match(EloquentSourceControlGateway::globPattern($glob), $path) === 1)->toBe($match);
})->with([
    ['*compose*.y*ml', 'docker-compose.yml', true],
    ['*compose*.y*ml', 'compose.yaml', true],
    ['*compose*.y*ml', 'composer.json', false],
    ['docker/**', 'docker/a/b.yml', true],
    ['**/compose.yaml', 'compose.yaml', true],
    ['**/compose.yaml', 'a/b/compose.yaml', true],
    ['*.yml', 'a/b.yml', false],
]);
