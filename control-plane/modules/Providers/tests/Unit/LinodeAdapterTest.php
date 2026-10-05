<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Infrastructure\Adapters\LinodeAdapter;

require_once __DIR__.'/../fixtures.php';

beforeEach(fn () => Http::preventStrayRequests());

function linode(): LinodeAdapter
{
    return new LinodeAdapter('linode-token', 'https://api.linode.com/v4', PROVIDERS_TEST_HTTP);
}

function linodeInstance(array $overrides = []): array
{
    return array_replace([
        'id' => 123,
        'label' => 'web-1',
        'status' => 'provisioning',
        'ipv4' => ['192.168.139.10', '203.0.113.1'],
        'ipv6' => '2600:3c03::f03c:91ff:fe24:3a2f/128',
        'region' => 'us-east',
    ], $overrides);
}

it('verifies via /profile', function () {
    Http::fake(['api.linode.com/v4/profile' => Http::response(['username' => 'x'])]);

    linode()->verify();

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer linode-token'));
});

it('maps field errors', function () {
    Http::fake(['*' => Http::response(['errors' => [['reason' => 'Invalid Token']]], 401)]);

    expect(fn () => linode()->verify())->toThrow(ProviderException::class, 'Akamai / Linode: Invalid Token');
});

it('paginates regions by page count', function () {
    Http::fake([
        'api.linode.com/v4/regions?page=1*' => Http::response(['data' => [['id' => 'us-east', 'label' => 'Newark, NJ', 'country' => 'us', 'status' => 'ok']], 'page' => 1, 'pages' => 2]),
        'api.linode.com/v4/regions?page=2*' => Http::response(['data' => [['id' => 'eu-central', 'label' => 'Frankfurt, DE', 'country' => 'de', 'status' => 'outage']], 'page' => 2, 'pages' => 2]),
    ]);

    $regions = linode()->regions();

    expect($regions)->toHaveCount(2)
        ->and($regions[0]->country)->toBe('US')
        ->and($regions[1]->available)->toBeFalse();
});

it('lists types with regional pricing', function () {
    Http::fake(['api.linode.com/v4/linode/types*' => Http::response([
        'data' => [[
            'id' => 'g6-nanode-1', 'label' => 'Nanode 1GB', 'vcpus' => 1, 'memory' => 1024, 'disk' => 25600,
            'price' => ['monthly' => 5.0], 'region_prices' => [['id' => 'id-cgk', 'monthly' => 6.0]],
        ]],
        'page' => 1, 'pages' => 1,
    ])]);

    expect(linode()->sizes()[0]->priceMonthly)->toBe(5.0)
        ->and(linode()->sizes('id-cgk')[0]->priceMonthly)->toBe(6.0)
        ->and(linode()->sizes()[0]->diskGb)->toBe(25);
});

it('lists Ubuntu LTS images', function () {
    Http::fake(['api.linode.com/v4/images*' => Http::response([
        'data' => [
            ['id' => 'linode/ubuntu24.04', 'label' => 'Ubuntu 24.04 LTS', 'deprecated' => false],
            ['id' => 'linode/ubuntu22.04', 'label' => 'Ubuntu 22.04 LTS', 'deprecated' => false],
            ['id' => 'linode/debian12', 'label' => 'Debian 12', 'deprecated' => false],
        ],
        'page' => 1, 'pages' => 1,
    ])]);

    expect(array_map(fn ($i) => $i->id, linode()->images()))->toBe(['linode/ubuntu24.04', 'linode/ubuntu22.04']);
});

it('creates instances resolving key ids to key material, with a random root password and metadata user data', function () {
    Http::fake([
        'api.linode.com/v4/profile/sshkeys/77' => Http::response(['id' => 77, 'label' => 'dev', 'ssh_key' => PROVIDERS_TEST_KEY]),
        'api.linode.com/v4/linode/instances' => Http::response(linodeInstance()),
    ]);

    $machine = linode()->createServer(new MachineSpec('web 1', 'us-east', 'g6-nanode-1', 'linode/ubuntu24.04', ['77'], 'echo hi', ['falak-server' => '01J']));

    expect($machine->id)->toBe('123')
        ->and($machine->status)->toBe(Machine::STATUS_PROVISIONING)
        ->and($machine->ipv4)->toBe('203.0.113.1')
        ->and($machine->privateIpv4)->toBe('192.168.139.10')
        ->and($machine->ipv6)->toBe('2600:3c03::f03c:91ff:fe24:3a2f');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.linode.com/v4/linode/instances'
        && $r['label'] === 'web-1'
        && $r['authorized_keys'] === [PROVIDERS_TEST_KEY]
        && $r['metadata'] === ['user_data' => base64_encode('echo hi')]
        && strlen($r['root_pass']) >= 32
        && $r['booted'] === true
        && $r['tags'] === ['falak-server:01J']);
});

it('generates a different root password per server', function () {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(linodeInstance())]);

    linode()->createServer(new MachineSpec('a', 'us-east', 't', 'linode/ubuntu24.04'));
    linode()->createServer(new MachineSpec('b', 'us-east', 't', 'linode/ubuntu24.04'));

    $passwords = collect(Http::recorded())->map(fn ($pair) => $pair[0]['root_pass'])->unique();
    expect($passwords)->toHaveCount(2);
});

it('gets and deletes instances idempotently', function () {
    Http::fake([
        'api.linode.com/v4/linode/instances/1' => Http::response(linodeInstance(['status' => 'running'])),
        'api.linode.com/v4/linode/instances/2' => Http::response(['errors' => [['reason' => 'Not found']]], 404),
    ]);

    expect(linode()->getServer('1')?->status)->toBe(Machine::STATUS_RUNNING)
        ->and(linode()->getServer('2'))->toBeNull();

    linode()->destroyServer('2');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/linode/instances/2'));
});

it('reuses or uploads profile ssh keys', function () {
    Http::fake([
        'api.linode.com/v4/profile/sshkeys?*' => Http::response(['data' => [['id' => 5, 'label' => 'other', 'ssh_key' => 'ssh-rsa AAAAB3NzaC1yc2E= other']], 'page' => 1, 'pages' => 1]),
        'api.linode.com/v4/profile/sshkeys' => Http::response(['id' => 6, 'label' => 'dev', 'ssh_key' => PROVIDERS_TEST_KEY]),
        'api.linode.com/v4/profile/sshkeys/6' => Http::response([]),
    ]);

    expect(linode()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe('6');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['label'] === 'dev');

    linode()->deleteSshKey('6');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/profile/sshkeys/6'));
});

it('sanitizes labels to Linode rules (no consecutive separators, 3-64 chars)', function (string $name, string $label) {
    Http::fake(['api.linode.com/v4/linode/instances' => Http::response(linodeInstance())]);

    linode()->createServer(new MachineSpec($name, 'us-east', 'g6-nanode-1', 'linode/ubuntu24.04'));

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request['label'] === $label);
})->with([
    ['my--app', 'my-app'],
    ['web .. prod__1', 'web-prod_1'],
    ['__x__', 'x00'],
    [str_repeat('a', 70).'-b', str_repeat('a', 64)],
]);
