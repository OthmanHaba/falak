<?php

use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Infrastructure\Adapters\HetznerAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../fixtures.php';

beforeEach(fn () => Http::preventStrayRequests());

function hetzner(): HetznerAdapter
{
    return new HetznerAdapter('hcloud-secret', 'https://api.hetzner.cloud/v1', PROVIDERS_TEST_HTTP);
}

function hetznerServer(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 42,
        'name' => 'web-1',
        'status' => 'initializing',
        'public_net' => ['ipv4' => ['ip' => '203.0.113.10'], 'ipv6' => ['ip' => '2001:db8:1::/64']],
        'private_net' => [],
        'datacenter' => ['name' => 'fsn1-dc14', 'location' => ['name' => 'fsn1']],
    ], $overrides);
}

it('verifies the token with an authenticated call', function () {
    Http::fake(['api.hetzner.cloud/v1/locations*' => Http::response(['locations' => []])]);

    hetzner()->verify();

    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && str_starts_with($r->url(), 'https://api.hetzner.cloud/v1/locations')
        && $r->hasHeader('Authorization', 'Bearer hcloud-secret'));
});

it('maps authentication errors to ProviderException with status', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401)]);

    try {
        hetzner()->verify();
        $this->fail('expected exception');
    } catch (ProviderException $e) {
        expect($e->status)->toBe(401)
            ->and($e->provider)->toBe('hetzner')
            ->and($e->isAuthenticationError())->toBeTrue()
            ->and($e->getMessage())->toBe('Hetzner Cloud: unable to authenticate');
    }

    Http::assertSentCount(1); // 401 is never retried
});

it('lists locations across pages', function () {
    Http::fake([
        'api.hetzner.cloud/v1/locations?page=1*' => Http::response([
            'locations' => [['id' => 1, 'name' => 'fsn1', 'description' => 'Falkenstein DC Park 1', 'country' => 'DE', 'city' => 'Falkenstein']],
            'meta' => ['pagination' => ['page' => 1, 'next_page' => 2]],
        ]),
        'api.hetzner.cloud/v1/locations?page=2*' => Http::response([
            'locations' => [['id' => 2, 'name' => 'ash', 'description' => 'Ashburn, VA', 'country' => 'US', 'city' => 'Ashburn, VA']],
            'meta' => ['pagination' => ['page' => 2, 'next_page' => null]],
        ]),
    ]);

    $regions = hetzner()->regions();

    expect($regions)->toHaveCount(2)
        ->and($regions[0]->id)->toBe('fsn1')
        ->and($regions[0]->country)->toBe('DE')
        ->and($regions[1]->id)->toBe('ash');
});

it('lists server types with arm architecture, regional price and skips deprecated types', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response([
        'server_types' => [
            ['id' => 1, 'name' => 'cx22', 'description' => 'CX22', 'cores' => 2, 'memory' => 4.0, 'disk' => 40, 'architecture' => 'x86', 'deprecation' => null,
                'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '4.5900000000']], ['location' => 'ash', 'price_monthly' => ['gross' => '5.9900000000']]]],
            ['id' => 2, 'name' => 'cax11', 'description' => 'CAX11', 'cores' => 2, 'memory' => 4.0, 'disk' => 40, 'architecture' => 'arm', 'deprecation' => null,
                'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '4.5100000000']]]],
            ['id' => 3, 'name' => 'cx11', 'description' => 'old', 'cores' => 1, 'memory' => 2.0, 'disk' => 20, 'architecture' => 'x86',
                'deprecation' => ['announced' => '2024-01-01'], 'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '3.00']]]],
        ],
        'meta' => ['pagination' => ['next_page' => null]],
    ])]);

    $all = hetzner()->sizes();
    $ash = hetzner()->sizes('ash');

    expect($all)->toHaveCount(2)
        ->and($all[1]->arch)->toBe('arm64')
        ->and($all[0]->memoryMb)->toBe(4096)
        ->and($all[0]->regions)->toBe(['fsn1', 'ash'])
        ->and($ash)->toHaveCount(1)
        ->and($ash[0]->priceMonthly)->toBe(5.99);
});

it('lists only Ubuntu LTS system images per architecture', function () {
    Http::fake(['api.hetzner.cloud/v1/images*' => Http::response([
        'images' => [
            ['id' => 161547269, 'name' => 'ubuntu-24.04', 'description' => 'Ubuntu 24.04', 'os_flavor' => 'ubuntu', 'os_version' => '24.04', 'architecture' => 'x86'],
            ['id' => 161547270, 'name' => 'ubuntu-24.04', 'description' => 'Ubuntu 24.04', 'os_flavor' => 'ubuntu', 'os_version' => '24.04', 'architecture' => 'arm'],
            ['id' => 67794396, 'name' => 'ubuntu-20.04', 'description' => 'Ubuntu 20.04', 'os_flavor' => 'ubuntu', 'os_version' => '20.04', 'architecture' => 'x86'],
            ['id' => 114690387, 'name' => 'debian-12', 'description' => 'Debian 12', 'os_flavor' => 'debian', 'os_version' => '12', 'architecture' => 'x86'],
        ],
        'meta' => ['pagination' => ['next_page' => null]],
    ])]);

    $images = hetzner()->images();

    expect($images)->toHaveCount(2)
        ->and($images[0]->id)->toBe('161547269')
        ->and($images[1]->arch)->toBe('arm64');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'type=system') && str_contains($r->url(), 'status=available'));
});

it('creates a server with user data, labels and ssh keys', function () {
    Http::fake(['api.hetzner.cloud/v1/servers' => Http::response(['server' => hetznerServer(), 'root_password' => null], 201)]);

    $machine = hetzner()->createServer(new MachineSpec(
        name: 'Web 1',
        region: 'fsn1',
        size: 'cx22',
        image: '161547269',
        sshKeyIds: ['7'],
        userData: "#!/bin/sh\ncurl -fsSL https://falak.test/install/abc | sh",
        labels: ['falak-server' => '01JABC', 'falak/org' => 'x y'],
    ));

    expect($machine->id)->toBe('42')
        ->and($machine->status)->toBe(Machine::STATUS_PROVISIONING)
        ->and($machine->ipv4)->toBe('203.0.113.10')
        ->and($machine->ipv6)->toBe('2001:db8:1::1')
        ->and($machine->region)->toBe('fsn1');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.hetzner.cloud/v1/servers'
        && $r['name'] === 'web-1'
        && $r['server_type'] === 'cx22'
        && $r['location'] === 'fsn1'
        && $r['image'] === 161547269
        && $r['ssh_keys'] === [7]
        && str_contains($r['user_data'], '/install/abc')
        && $r['labels'] === ['falak-server' => '01JABC', 'falak-org' => 'x-y']
        && $r['public_net'] === ['enable_ipv4' => true, 'enable_ipv6' => true]);
});

it('never retries a failed create (5xx) to avoid duplicate servers', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'internal error']], 503)]);

    expect(fn () => hetzner()->createServer(new MachineSpec('a', 'fsn1', 'cx22', 'ubuntu-24.04')))
        ->toThrow(ProviderException::class, 'internal error');

    Http::assertSentCount(1);
});

it('retries idempotent reads on 5xx and rate limits', function () {
    Http::fakeSequence()
        ->push(['error' => ['message' => 'rate limited']], 429)
        ->push(['error' => ['message' => 'unavailable']], 503)
        ->push(['server' => hetznerServer(['status' => 'running'])]);

    $machine = hetzner()->getServer('42');

    expect($machine?->status)->toBe(Machine::STATUS_RUNNING);
    Http::assertSentCount(3);
});

it('returns null for missing servers and treats destroy of a missing server as success', function () {
    Http::fake([
        'api.hetzner.cloud/v1/servers/404' => Http::response(['error' => ['code' => 'not_found', 'message' => 'server not found']], 404),
    ]);

    expect(hetzner()->getServer('404'))->toBeNull();
    hetzner()->destroyServer('404');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.hetzner.cloud/v1/servers/404');
});

it('reuses an existing ssh key by fingerprint', function () {
    Http::fake(['api.hetzner.cloud/v1/ssh_keys*' => Http::response(['ssh_keys' => [['id' => 99, 'name' => 'dev', 'fingerprint' => '03:84:3e:7e:c3:64:5d:07:86:ad:8c:f0:43:29:85:7d']]])]);

    expect(hetzner()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe('99');

    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains($r->url(), 'fingerprint=03%3A84%3A3e'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('uploads a new ssh key and retries with a suffixed name on name conflicts', function () {
    Http::fake([
        'api.hetzner.cloud/v1/ssh_keys?*' => Http::response(['ssh_keys' => []]),
        'api.hetzner.cloud/v1/ssh_keys' => Http::sequence()
            ->push(['error' => ['code' => 'uniqueness_error', 'message' => 'SSH key with the same name already exists']], 409)
            ->push(['ssh_key' => ['id' => 100]], 201),
    ]);

    expect(hetzner()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe('100');

    $posts = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST')->values();
    expect($posts)->toHaveCount(2)
        ->and($posts[0][0]['name'])->toBe('dev')
        ->and($posts[1][0]['name'])->toStartWith('dev-')
        ->and($posts[1][0]['public_key'])->toBe('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV');
});

it('deletes ssh keys idempotently', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'not found']], 404)]);

    hetzner()->deleteSshKey('5');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.hetzner.cloud/v1/ssh_keys/5');
});

it('wraps connection failures', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => hetzner()->verify())->toThrow(ProviderException::class, 'Hetzner Cloud API is unreachable');
});
