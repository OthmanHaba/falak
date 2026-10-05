<?php

use Falak\Identity\Domain\Models\User;
use Falak\Kernel\Support\SharedProps;
use Illuminate\Http\Request;

it('resolves registered props lazily and only for signed-in users unless marked public', function () {
    $props = new SharedProps;
    $calls = 0;
    $props->register('private', function (Request $request) use (&$calls) {
        $calls++;

        return $request->path();
    });
    $props->register('public', fn () => 'hello', authenticated: false);

    $guest = Request::create('/a');
    expect(array_keys($props->for($guest)))->toBe(['public']);

    $request = Request::create('/b');
    $request->setUserResolver(fn () => new User);
    $shared = $props->for($request);

    expect(array_keys($shared))->toBe(['private', 'public'])
        ->and($calls)->toBe(0)
        ->and($shared['private']())->toBe('b')
        ->and($shared['public']())->toBe('hello')
        ->and($props->has('private'))->toBeTrue()
        ->and($props->has('nope'))->toBeFalse();
});
