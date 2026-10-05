<?php

use Illuminate\Support\Facades\Http;
use Falak\Templates\Application\Import\FetchFailed;
use Falak\Templates\Application\Import\HostResolver;
use Falak\Templates\Application\Import\RemoteFetcher;
use Falak\Templates\Infrastructure\GuardedHttpFetcher;
use Falak\Templates\Tests\Support\FakeHostResolver;

beforeEach(function () {
    app()->instance(HostResolver::class, new FakeHostResolver([
        'templates.example.com' => ['93.184.216.34'],
        'other.example.com' => ['2606:2800:220:1:248:1893:25c8:1946'],
        'internal.example.com' => ['10.0.0.5'],
        'mixed.example.com' => ['93.184.216.34', '127.0.0.1'],
        'metadata.example.com' => ['169.254.169.254'],
    ]));
});

it('classifies addresses', function (string $ip, bool $public) {
    expect(GuardedHttpFetcher::isPublic($ip))->toBe($public);
})->with([
    ['93.184.216.34', true], ['2606:2800:220:1:248:1893:25c8:1946', true],
    ['127.0.0.1', false], ['10.1.2.3', false], ['172.16.0.1', false], ['192.168.1.1', false], ['169.254.169.254', false],
    ['100.64.0.1', false], ['0.0.0.0', false], ['::1', false], ['fe80::1', false], ['fd00::1', false], ['::ffff:127.0.0.1', false],
    ['::ffff:10.0.0.1', false], ['198.18.0.1', false], ['224.0.0.1', false],
]);

it('fetches public https URLs', function () {
    Http::fake(['https://templates.example.com/*' => Http::response("name: x\n")]);

    expect(app(RemoteFetcher::class)->fetch('https://templates.example.com/a/template.yaml'))->toBe("name: x\n");
});

it('refuses unsafe URLs', function (string $url, string $message) {
    Http::fake();

    expect(fn () => app(RemoteFetcher::class)->fetch($url))->toThrow(FetchFailed::class, $message);
    Http::assertNothingSent();
})->with([
    'http' => ['http://templates.example.com/x', 'Only https://'],
    'file' => ['file:///etc/passwd', 'Only https://'],
    'credentials' => ['https://user:pw@templates.example.com/x', 'credentials'],
    'private' => ['https://internal.example.com/x', 'private or reserved'],
    'any private address' => ['https://mixed.example.com/x', 'private or reserved'],
    'metadata' => ['https://metadata.example.com/latest', 'private or reserved'],
    'loopback literal' => ['https://127.0.0.1/x', 'private or reserved'],
    'ipv6 loopback literal' => ['https://[::1]/x', 'private or reserved'],
    'unresolvable' => ['https://nowhere.example.com/x', 'does not resolve'],
]);

it('re-checks every redirect', function () {
    Http::fake([
        'https://templates.example.com/*' => Http::response('', 302, ['Location' => 'https://internal.example.com/secret']),
    ]);

    expect(fn () => app(RemoteFetcher::class)->fetch('https://templates.example.com/t.yaml'))->toThrow(FetchFailed::class, 'private or reserved');
});

it('follows safe redirects', function () {
    Http::fake([
        'https://templates.example.com/*' => Http::response('', 301, ['Location' => 'https://other.example.com/t.yaml']),
        'https://other.example.com/*' => Http::response('ok'),
    ]);

    expect(app(RemoteFetcher::class)->fetch('https://templates.example.com/t.yaml'))->toBe('ok');
});

it('caps the size and rejects binary content', function () {
    config(['templates.max_bytes' => 1024]);
    Http::fake([
        'https://templates.example.com/big' => Http::response(str_repeat('a', 2048)),
        'https://templates.example.com/bin' => Http::response("\x00\x01\x02"),
        'https://templates.example.com/gone' => Http::response('', 404),
    ]);

    expect(fn () => app(RemoteFetcher::class)->fetch('https://templates.example.com/big'))->toThrow(FetchFailed::class, 'larger than 1 KB')
        ->and(fn () => app(RemoteFetcher::class)->fetch('https://templates.example.com/bin'))->toThrow(FetchFailed::class, 'not UTF-8 text')
        ->and(fn () => app(RemoteFetcher::class)->fetch('https://templates.example.com/gone'))->toThrow(FetchFailed::class, 'HTTP 404');
});
