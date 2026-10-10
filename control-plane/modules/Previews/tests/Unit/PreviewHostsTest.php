<?php

use Falak\Previews\Application\PreviewHosts;

it('fills the pattern into one DNS label under the preview domain', function () {
    $host = PreviewHosts::host('pr-{number}-{service}', 12, 'Shop API', 'Acme', 'p1', 'prv.example.com', fn () => true);

    expect($host)->toBe('pr-12-shop-api.prv.example.com')
        ->and(PreviewHosts::host('{project}-{number}-{service}', 3, 'web', 'My Shop', 'p1', 'prv.example.com', fn () => true))->toBe('my-shop-3-web.prv.example.com');
});

it('suffixes a label another project already uses, and keeps it within 63 characters', function () {
    $taken = ['pr-1-web.prv.example.com'];
    $host = PreviewHosts::host('pr-{number}-{service}', 1, 'web', 'Acme', 'project-b', 'prv.example.com', fn (string $h) => ! in_array($h, $taken, true));

    expect($host)->toStartWith('pr-1-web-')->not->toBe('pr-1-web.prv.example.com');

    $long = PreviewHosts::host('pr-{number}-{service}', 1, str_repeat('service', 20), 'Acme', 'p', 'prv.example.com', fn (string $h) => ! str_ends_with(explode('.', $h)[0], 'e'));
    expect(strlen(explode('.', $long)[0]))->toBeLessThanOrEqual(63);
});

it('accepts patterns with {number} and {service} only', function (string $pattern, bool $valid) {
    expect(PreviewHosts::validPattern($pattern))->toBe($valid);
})->with([
    ['pr-{number}-{service}', true],
    ['{service}-{number}-{project}', true],
    ['pr-{number}', false],
    ['pr-{number}-{service}.evil', false],
    ['PR-{number}-{service}', false],
]);
