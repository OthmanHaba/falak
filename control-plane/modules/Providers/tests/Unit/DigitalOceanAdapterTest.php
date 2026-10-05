<?php

use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Infrastructure\Adapters\DigitalOceanAdapter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../fixtures.php';

beforeEach(fn () => Http::preventStrayRequests());

function digitalocean(): DigitalOceanAdapter
{
    return new DigitalOceanAdapter('dop_v1_secret', 'https://api.digitalocean.com/v2', PROVIDERS_TEST_HTTP);
}

function droplet(array $overrides = []): array
{
    return array_replace([
        'id' => 3164444,
        'name' => 'web-1',
        'status' => 'new',
        'networks' => [
            'v4' => [['ip_address' => '10.128.192.124', 'type' => 'private'], ['ip_address' => '192.241.165.154', 'type' => 'public']],
            'v6' => [['ip_address' => '2604:a880:0:1010::18a:a001', 'type' => 'public']],
        ],
        'region' => ['slug' => 'fra1', 'name' => 'Frankfurt 1'],
    ], $overrides);
}

it('verifies against /account with the bearer token', function () {
    Http::fake(['api.digitalocean.com/v2/account' => Http::response(['account' => ['status' => 'active']])]);

    digitalocean()->verify();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.digitalocean.com/v2/account' && $r->hasHeader('Authorization', 'Bearer dop_v1_secret'));
});

it('maps API errors', function () {
    Http::fake(['*' => Http::response(['id' => 'unauthorized', 'message' => 'Unable to authenticate you'], 401)]);

    expect(fn () => digitalocean()->verify())->toThrow(ProviderException::class, 'DigitalOcean: Unable to authenticate you');
});

it('paginates regions following links.pages.next', function () {
    Http::fake([
        'api.digitalocean.com/v2/regions?page=1*' => Http::response([
            'regions' => [['slug' => 'nyc3', 'name' => 'New York 3', 'available' => true]],
            'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/regions?page=2&per_page=200']],
        ]),
        'api.digitalocean.com/v2/regions?page=2*' => Http::response([
            'regions' => [['slug' => 'fra1', 'name' => 'Frankfurt 1', 'available' => false]],
            'links' => ['pages' => []],
        ]),
    ]);

    $regions = digitalocean()->regions();

    expect($regions)->toHaveCount(2)
        ->and($regions[0]->country)->toBe('US')
        ->and($regions[1]->country)->toBe('DE')
        ->and($regions[1]->available)->toBeFalse();
});

it('filters sizes by region and availability', function () {
    Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response([
        'sizes' => [
            ['slug' => 's-1vcpu-1gb', 'description' => 'Basic', 'memory' => 1024, 'vcpus' => 1, 'disk' => 25, 'price_monthly' => 6.0, 'regions' => ['nyc3', 'fra1'], 'available' => true],
            ['slug' => 's-2vcpu-4gb', 'description' => 'Basic', 'memory' => 4096, 'vcpus' => 2, 'disk' => 80, 'price_monthly' => 24.0, 'regions' => ['nyc3'], 'available' => true],
            ['slug' => 'gone', 'description' => 'Old', 'memory' => 512, 'vcpus' => 1, 'disk' => 20, 'price_monthly' => 4.0, 'regions' => ['fra1'], 'available' => false],
        ],
        'links' => [],
    ])]);

    $sizes = digitalocean()->sizes('fra1');

    expect($sizes)->toHaveCount(1)
        ->and($sizes[0]->id)->toBe('s-1vcpu-1gb')
        ->and($sizes[0]->memoryMb)->toBe(1024)
        ->and($sizes[0]->priceMonthly)->toBe(6.0);
});

it('lists Ubuntu LTS distribution images', function () {
    Http::fake(['api.digitalocean.com/v2/images*' => Http::response([
        'images' => [
            ['id' => 1, 'slug' => 'ubuntu-24-04-x64', 'distribution' => 'Ubuntu', 'name' => '24.04 (LTS) x64'],
            ['id' => 2, 'slug' => 'ubuntu-22-04-x64', 'distribution' => 'Ubuntu', 'name' => '22.04 (LTS) x64'],
            ['id' => 3, 'slug' => 'ubuntu-20-04-x64', 'distribution' => 'Ubuntu', 'name' => '20.04 (LTS) x64'],
            ['id' => 4, 'slug' => 'debian-12-x64', 'distribution' => 'Debian', 'name' => '12 x64'],
        ],
        'links' => [],
    ])]);

    $images = digitalocean()->images();

    expect(array_map(fn ($i) => $i->id, $images))->toBe(['ubuntu-24-04-x64', 'ubuntu-22-04-x64'])
        ->and($images[0]->version)->toBe('24.04');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'type=distribution'));
});

it('creates droplets with user data, ssh keys and tags', function () {
    Http::fake(['api.digitalocean.com/v2/droplets' => Http::response(['droplet' => droplet()], 202)]);

    $machine = digitalocean()->createServer(new MachineSpec('web 1', 'fra1', 's-1vcpu-1gb', 'ubuntu-24-04-x64', ['512190'], "#!/bin/sh\necho hi", ['falak-server' => '01JABC']));

    expect($machine->id)->toBe('3164444')
        ->and($machine->status)->toBe(Machine::STATUS_PROVISIONING)
        ->and($machine->ipv4)->toBe('192.241.165.154')
        ->and($machine->privateIpv4)->toBe('10.128.192.124')
        ->and($machine->ipv6)->toBe('2604:a880:0:1010::18a:a001');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r['name'] === 'web-1'
        && $r['region'] === 'fra1'
        && $r['size'] === 's-1vcpu-1gb'
        && $r['image'] === 'ubuntu-24-04-x64'
        && $r['ssh_keys'] === [512190]
        && $r['user_data'] === "#!/bin/sh\necho hi"
        && $r['ipv6'] === true
        && $r['tags'] === ['falak-server:01JABC']);
});

it('gets and destroys droplets idempotently', function () {
    Http::fake([
        'api.digitalocean.com/v2/droplets/1' => Http::response(['droplet' => droplet(['status' => 'active'])]),
        'api.digitalocean.com/v2/droplets/2' => Http::response(['id' => 'not_found', 'message' => 'The resource you were accessing could not be found.'], 404),
    ]);

    expect(digitalocean()->getServer('1')?->status)->toBe(Machine::STATUS_RUNNING)
        ->and(digitalocean()->getServer('2'))->toBeNull();

    digitalocean()->destroyServer('2');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.digitalocean.com/v2/droplets/2');
});

it('reuses an existing key looked up by fingerprint', function () {
    Http::fake(['api.digitalocean.com/v2/account/keys/03:84:3e:7e:c3:64:5d:07:86:ad:8c:f0:43:29:85:7d' => Http::response(['ssh_key' => ['id' => 512189]])]);

    expect(digitalocean()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe('512189');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('uploads keys that do not exist yet', function () {
    Http::fake([
        'api.digitalocean.com/v2/account/keys/*' => Http::response(['id' => 'not_found', 'message' => 'not found'], 404),
        'api.digitalocean.com/v2/account/keys' => Http::response(['ssh_key' => ['id' => 512190]], 201),
    ]);

    expect(digitalocean()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe('512190');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'dev'
        && $r['public_key'] === 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV');
});

it('deletes keys', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    digitalocean()->deleteSshKey('512190');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.digitalocean.com/v2/account/keys/512190');
});
