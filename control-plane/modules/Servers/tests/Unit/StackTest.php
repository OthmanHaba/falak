<?php

use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Stack\Stack;

it('has valid defaults for every server type', function (ServerType $type) {
    expect(Stack::defaultsFor($type)->errorsFor($type))->toBe([]);
})->with(ServerType::cases());

it('rejects components a server type cannot run', function () {
    expect((new Stack('frankenphp', ['8.4'], '8.4'))->errorsFor(ServerType::Cache))->toHaveKey('stack.php')
        ->and((new Stack(node: '22'))->errorsFor(ServerType::Database))->toHaveKey('stack.node');
});

it('validates PHP selections', function () {
    expect((new Stack('fpm', ['7.0'], '7.0'))->errorsFor(ServerType::Web))->toHaveKey('stack.php.versions')
        ->and((new Stack('fpm', ['8.4'], '8.3'))->errorsFor(ServerType::Web))->toHaveKey('stack.php.default')
        ->and((new Stack('apache', ['8.4'], '8.4'))->errorsFor(ServerType::Web))->toHaveKey('stack.php.runtime');
});

it('validates Node and accepts database and cache servers with no components', function () {
    expect((new Stack(node: '8'))->errorsFor(ServerType::Builder))->toHaveKey('stack.node')
        ->and((new Stack(node: '22'))->errorsFor(ServerType::Builder))->toBe([])
        ->and((new Stack)->errorsFor(ServerType::Database))->toBe([])
        ->and((new Stack)->errorsFor(ServerType::Cache))->toBe([]);
});

it('round-trips through arrays', function () {
    $stack = new Stack('fpm', ['8.3', '8.4'], '8.4', '22');

    expect(Stack::fromArray($stack->toArray()))->toEqual($stack)
        // Stacks stored before v0.10 carry database / cache / docker: ignored (databases are containers now).
        ->and(Stack::fromArray(['php' => null, 'node' => '', 'database' => 'mysql', 'cache' => 'redis', 'docker' => false]))->toEqual(new Stack);
});
