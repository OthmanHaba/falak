<?php

use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Stack\Stack;

it('has valid defaults for every server type', function (ServerType $type) {
    expect(Stack::defaultsFor($type)->errorsFor($type))->toBe([]);
})->with(ServerType::cases());

it('rejects components a server type cannot run', function () {
    $errors = (new Stack('frankenphp', ['8.4'], '8.4', database: 'postgresql'))->errorsFor(ServerType::Cache);

    expect($errors)->toHaveKeys(['stack.php', 'stack.database', 'stack.cache']);
});

it('validates PHP selections', function () {
    expect((new Stack('fpm', ['7.0'], '7.0'))->errorsFor(ServerType::Web))->toHaveKey('stack.php.versions')
        ->and((new Stack('fpm', ['8.4'], '8.3'))->errorsFor(ServerType::Web))->toHaveKey('stack.php.default')
        ->and((new Stack('apache', ['8.4'], '8.4'))->errorsFor(ServerType::Web))->toHaveKey('stack.php.runtime');
});

it('validates engines and requirements', function () {
    expect((new Stack(database: 'oracle'))->errorsFor(ServerType::Database))->toHaveKey('stack.database')
        ->and((new Stack)->errorsFor(ServerType::Database))->toHaveKey('stack.database')
        ->and((new Stack(node: '22'))->errorsFor(ServerType::Builder))->toHaveKey('stack.docker')
        ->and((new Stack(node: '8'))->errorsFor(ServerType::Builder))->toHaveKey('stack.node');
});

it('round-trips through arrays', function () {
    $stack = new Stack('fpm', ['8.3', '8.4'], '8.4', '22', 'mysql', 'redis', true);

    expect(Stack::fromArray($stack->toArray()))->toEqual($stack)
        ->and(Stack::fromArray(['php' => null, 'node' => '', 'docker' => false]))->toEqual(new Stack);
});
