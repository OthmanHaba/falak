<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kiln\Providers\Contracts\Data\Machine;
use Kiln\Providers\Contracts\Data\MachineSpec;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Infrastructure\Adapters\VultrAdapter;

require_once __DIR__.'/../fixtures.php';

beforeEach(fn () => Http::preventStrayRequests());

function vultr(): VultrAdapter
{
    return new VultrAdapter('vultr-key', 'https://api.vultr.com/v2', PROVIDERS_TEST_HTTP);
}

function vultrInstance(array $overrides = []): array
{
    return array_replace([
        'id' => 'cb676a46-66fd-4dfb-b839-443f2e6c0b60',
        'label' => 'web-1',
        'status' => 'pending',
        'power_status' => 'running',
        'server_status' => 'none',
        'main_ip' => '0.0.0.0',
        'v6_main_ip' => '',
        'internal_ip' => '',
        'region' => 'ewr',
    ], $overrides);
}

it('verifies against /account', function () {
    Http::fake(['api.vultr.com/v2/account' => Http::response(['account' => ['name' => 'x']])]);

    vultr()->verify();

    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer vultr-key'));
});

it('maps errors', function () {
    Http::fake(['*' => Http::response(['error' => 'Invalid API token.', 'status' => 401], 401)]);

    expect(fn () => vultr()->verify())->toThrow(ProviderException::class, 'Vultr: Invalid API token.');
});

it('follows cursor pagination for regions', function () {
    Http::fake([
        'api.vultr.com/v2/regions?per_page=500' => Http::response([
            'regions' => [['id' => 'ewr', 'city' => 'New Jersey', 'country' => 'US']],
            'meta' => ['links' => ['next' => 'bmV4dF9fQU1T']],
        ]),
        'api.vultr.com/v2/regions?per_page=500&cursor=bmV4dF9fQU1T' => Http::response([
            'regions' => [['id' => 'ams', 'city' => 'Amsterdam', 'country' => 'NL']],
            'meta' => ['links' => ['next' => '']],
        ]),
    ]);

    $regions = vultr()->regions();

    expect(array_map(fn ($r) => $r->id, $regions))->toBe(['ewr', 'ams'])
        ->and($regions[1]->name)->toBe('Amsterdam, NL');
});

it('filters plans by region', function () {
    Http::fake(['api.vultr.com/v2/plans*' => Http::response([
        'plans' => [
            ['id' => 'vc2-1c-1gb', 'vcpu_count' => 1, 'ram' => 1024, 'disk' => 25, 'monthly_cost' => 5, 'locations' => ['ewr', 'ams']],
            ['id' => 'vhf-2c-4gb', 'vcpu_count' => 2, 'ram' => 4096, 'disk' => 128, 'monthly_cost' => 24, 'locations' => ['ewr']],
            ['id' => 'retired', 'vcpu_count' => 1, 'ram' => 512, 'disk' => 10, 'monthly_cost' => 2.5, 'locations' => []],
        ],
        'meta' => ['links' => ['next' => '']],
    ])]);

    expect(vultr()->sizes())->toHaveCount(2)
        ->and(vultr()->sizes('ams'))->toHaveCount(1);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'type=all'));
});

it('lists Ubuntu LTS operating systems', function () {
    Http::fake(['api.vultr.com/v2/os*' => Http::response([
        'os' => [
            ['id' => 2284, 'name' => 'Ubuntu 24.04 LTS x64', 'arch' => 'x64', 'family' => 'ubuntu'],
            ['id' => 1743, 'name' => 'Ubuntu 22.04 LTS x64', 'arch' => 'x64', 'family' => 'ubuntu'],
            ['id' => 2136, 'name' => 'Debian 12 x64 (bookworm)', 'arch' => 'x64', 'family' => 'debian'],
        ],
        'meta' => ['links' => ['next' => '']],
    ])]);

    $images = vultr()->images();

    expect(array_map(fn ($i) => $i->id, $images))->toBe(['2284', '1743'])
        ->and($images[0]->arch)->toBe('amd64');
});

it('creates instances with base64 user data', function () {
    Http::fake(['api.vultr.com/v2/instances' => Http::response(['instance' => vultrInstance()], 202)]);

    $machine = vultr()->createServer(new MachineSpec('Web 1', 'ewr', 'vc2-1c-1gb', '2284', ['key-uuid'], 'echo hi', ['kiln-server' => '01J']));

    expect($machine->id)->toBe('cb676a46-66fd-4dfb-b839-443f2e6c0b60')
        ->and($machine->status)->toBe(Machine::STATUS_PROVISIONING)
        ->and($machine->ipv4)->toBeNull();

    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r['os_id'] === 2284
        && $r['plan'] === 'vc2-1c-1gb'
        && $r['region'] === 'ewr'
        && $r['hostname'] === 'web-1'
        && $r['label'] === 'Web 1'
        && $r['sshkey_id'] === ['key-uuid']
        && $r['user_data'] === base64_encode('echo hi')
        && $r['tags'] === ['kiln-server:01J']);
});

it('maps instance state and handles missing instances', function () {
    Http::fake([
        'api.vultr.com/v2/instances/a' => Http::response(['instance' => vultrInstance(['status' => 'active', 'server_status' => 'ok', 'main_ip' => '198.51.100.7', 'v6_main_ip' => '2001:19f0::1'])]),
        'api.vultr.com/v2/instances/b' => Http::response(['instance' => vultrInstance(['status' => 'active', 'power_status' => 'stopped'])]),
        'api.vultr.com/v2/instances/c' => Http::response(['error' => 'Invalid instance-id.'], 404),
    ]);

    $a = vultr()->getServer('a');

    expect($a?->status)->toBe(Machine::STATUS_RUNNING)
        ->and($a?->ipv4)->toBe('198.51.100.7')
        ->and(vultr()->getServer('b')?->status)->toBe(Machine::STATUS_STOPPED)
        ->and(vultr()->getServer('c'))->toBeNull();

    vultr()->destroyServer('c');
});

it('reuses an identical ssh key', function () {
    Http::fake([
        'api.vultr.com/v2/ssh-keys?*' => Http::response([
            'ssh_keys' => [['id' => 'existing-uuid', 'name' => 'dev', 'ssh_key' => PROVIDERS_TEST_KEY]],
            'meta' => ['links' => ['next' => '']],
        ]),
    ]);

    expect(vultr()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe('existing-uuid');
    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('uploads ssh keys that do not exist yet', function () {
    Http::fake([
        'api.vultr.com/v2/ssh-keys?*' => Http::response(['ssh_keys' => [], 'meta' => ['links' => ['next' => '']]]),
        'api.vultr.com/v2/ssh-keys' => Http::response(['ssh_key' => ['id' => 'new-uuid']], 201),
    ]);

    expect(vultr()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe('new-uuid');

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['ssh_key'] === 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV');
});

it('deletes ssh keys', function () {
    Http::fake(['*' => Http::response(null, 204)]);

    vultr()->deleteSshKey('k');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.vultr.com/v2/ssh-keys/k');
});
