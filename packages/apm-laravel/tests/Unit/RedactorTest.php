<?php

use Falak\Apm\Redactor;
use Falak\Apm\Span;

it('redacts denylisted attribute keys, url query params and SQL literals', function () {
    $redactor = new Redactor(['cache_keys' => ['users:*:profile']]);
    $span = new Span(str_repeat('a', 32), str_repeat('b', 16), null, 'x', Span::KIND_CLIENT, 1, [
        'falak.event.type' => 'outgoing_request',
        'url.full' => 'https://u:pw@api.test/x?token=abc&q=shoes&Authorization=1#frag',
        'http.request.header.authorization' => 'Bearer abc',
        'http.request.header.cookie' => 'sid=1',
        'db.query.text' => "update users set password = 'p@ss' where email = 'a@b.c' and id = ?",
        'falak.cache.key' => 'users:1:profile',
        'falak.custom' => 'visible',
    ]);

    $redactor->span($span);

    expect($span->attributes)->toMatchArray([
        'url.full' => 'https://u:[redacted]@api.test/x?token=%5Bredacted%5D&q=shoes&Authorization=%5Bredacted%5D#frag',
        'http.request.header.authorization' => '[redacted]',
        'http.request.header.cookie' => '[redacted]',
        'db.query.text' => 'update users set password = ? where email = ? and id = ?',
        'falak.cache.key' => '[redacted]',
        'falak.custom' => 'visible',
    ]);
});

it('redacts nested log context keys', function () {
    expect((new Redactor)->context(['user' => ['api_key' => 'k', 'name' => 'n'], 'Secret' => 's']))
        ->toBe(['user' => ['api_key' => '[redacted]', 'name' => 'n'], 'Secret' => '[redacted]']);
});

it('supports configured callbacks and survives broken ones', function () {
    $redactor = new Redactor(['callbacks' => [
        fn (array $a, string $type) => ['falak.event.type' => $type, 'rewritten' => true],
        fn () => throw new RuntimeException('bad callback'),
    ]]);
    $span = new Span(str_repeat('a', 32), str_repeat('b', 16), null, 'x', Span::KIND_INTERNAL, 1, ['falak.event.type' => 'cache']);

    $redactor->span($span);

    expect($span->attributes)->toBe(['falak.event.type' => 'cache', 'rewritten' => true]);
});
