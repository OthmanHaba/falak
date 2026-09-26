<?php

use Kiln\Insights\Domain\Support\Fingerprinter;
use Kiln\Insights\Domain\Support\StackTrace;

function php_trace(string $release, int $line = 42): string
{
    return implode("\n", [
        "#0 /srv/kiln/sites/shop/releases/{$release}/vendor/laravel/framework/src/Illuminate/Database/Connection.php(812): Illuminate\\Database\\Connection->runQueryCallback('select * from...', Array, Object(Closure))",
        "#1 /srv/kiln/sites/shop/releases/{$release}/app/Services/Checkout.php({$line}): Illuminate\\Database\\Connection->select('select...')",
        "#2 /srv/kiln/sites/shop/releases/{$release}/app/Http/Controllers/CheckoutController.php(".($line + 10).'): App\\Services\\Checkout->total(Object(App\\Models\\Cart))',
        '#3 [internal function]: App\\Http\\Controllers\\CheckoutController->store()',
        "#4 /srv/kiln/sites/shop/releases/{$release}/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): call_user_func_array(Array, Array)",
        '#5 {main}',
    ]);
}

it('groups the same exception across releases and line changes', function () {
    $fp = new Fingerprinter(3);

    $a = $fp->fingerprint('\\App\\Exceptions\\PaymentFailed', 'Card 4242 declined', php_trace('01J8AAAAAAAAAAAAAAAAAAAAAA', 42));
    $b = $fp->fingerprint('App\\Exceptions\\PaymentFailed', 'Card 1111 declined', php_trace('01J8BBBBBBBBBBBBBBBBBBBBBB', 57));

    expect($a->hash)->toBe($b->hash)
        ->and($a->type)->toBe('App\\Exceptions\\PaymentFailed')
        ->and($a->culprit)->toBe('Illuminate\\Database\\Connection->select (app/Services/Checkout.php:42)');
});

it('separates exceptions with different in-app frames or types', function () {
    $fp = new Fingerprinter(3);
    $base = $fp->fingerprint('RuntimeException', 'x', php_trace('01J8AAAAAAAAAAAAAAAAAAAAAA'));
    $otherFrames = $fp->fingerprint('RuntimeException', 'x', str_replace('Checkout.php', 'Invoice.php', php_trace('01J8AAAAAAAAAAAAAAAAAAAAAA')));
    $otherType = $fp->fingerprint('LogicException', 'x', php_trace('01J8AAAAAAAAAAAAAAAAAAAAAA'));

    expect($otherFrames->hash)->not->toBe($base->hash)
        ->and($otherType->hash)->not->toBe($base->hash);
});

it('ignores vendor frames when choosing the significant frames', function () {
    $frames = StackTrace::parse(php_trace('01J8AAAAAAAAAAAAAAAAAAAAAA'));

    expect(collect($frames)->where('inApp', true)->pluck('file')->all())->toBe(['app/Services/Checkout.php', 'app/Http/Controllers/CheckoutController.php'])
        ->and($frames[0]->file)->toBe('vendor/laravel/framework/src/Illuminate/Database/Connection.php')
        ->and($frames[0]->inApp)->toBeFalse()
        ->and($frames[0]->function)->toBe('Illuminate\\Database\\Connection->runQueryCallback')
        ->and($frames[3]->inApp)->toBeFalse()->and($frames[3]->file)->toBeNull(); // [internal function]
});

it('parses node stack traces', function () {
    $trace = "TypeError: Cannot read properties of undefined (reading 'id')\n"
        ."    at OrderService.load (/srv/kiln/sites/api/releases/01J8CCCCCCCCCCCCCCCCCCCCCC/dist/orders.js:12:15)\n"
        ."    at async Router.handle (/srv/kiln/sites/api/releases/01J8CCCCCCCCCCCCCCCCCCCCCC/node_modules/express/lib/router.js:5:3)\n"
        ."    at node:internal/process/task_queues:95:5\n"
        .'    at /srv/kiln/sites/api/current/dist/index.js:3:1';

    $frames = StackTrace::parse($trace);

    expect($frames)->toHaveCount(4)
        ->and($frames[0]->toArray())->toMatchArray(['file' => 'dist/orders.js', 'line' => 12, 'function' => 'OrderService.load', 'in_app' => true])
        ->and($frames[1]->inApp)->toBeFalse()
        ->and($frames[2]->inApp)->toBeFalse()
        ->and($frames[3]->toArray())->toMatchArray(['file' => 'dist/index.js', 'function' => null, 'in_app' => true]);
});

it('falls back to the normalized message without a stack trace', function () {
    $fp = new Fingerprinter;

    $a = $fp->fingerprint('Error', 'User 123 not found in "tenant-a" (id 9f0a1c2e-1111-2222-3333-444455556666)', null);
    $b = $fp->fingerprint('Error', 'User 987 not found in "tenant-b" (id 01234567-89ab-cdef-0123-456789abcdef)', '');
    $c = $fp->fingerprint('Error', 'Connection refused', null);

    expect($a->hash)->toBe($b->hash)->and($c->hash)->not->toBe($a->hash)
        ->and(Fingerprinter::normalizeMessage('Order 01J8AAAAAAAAAAAAAAAAAAAAAA failed after 3.5s'))->toBe('order <ulid> failed after <n>s');
});
