<?php

use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Infrastructure\Adapters\LightsailAdapter;
use Falak\Providers\Infrastructure\Aws\SigV4Signer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../fixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-09-26T10:00:00Z');
});

afterEach(fn () => Carbon::setTestNow());

function lightsail(): LightsailAdapter
{
    return new LightsailAdapter('AKIAEXAMPLE', 'secret-key', 'us-east-1', 'https://lightsail.{region}.amazonaws.com', PROVIDERS_TEST_HTTP);
}

function isLightsailOp(Request $r, string $op, string $region = 'us-east-1'): bool
{
    return $r->method() === 'POST'
        && $r->url() === "https://lightsail.{$region}.amazonaws.com/"
        && $r->hasHeader('X-Amz-Target', "Lightsail_20161128.{$op}")
        && str_starts_with($r->header('Content-Type')[0] ?? '', 'application/x-amz-json-1.1');
}

it('signs every call with SigV4 for the lightsail service and region', function () {
    Http::fake(['lightsail.us-east-1.amazonaws.com/*' => Http::response(['regions' => []])]);

    lightsail()->verify();

    Http::assertSent(function (Request $r) {
        $body = $r->body();
        $expected = (new SigV4Signer('AKIAEXAMPLE', 'secret-key', 'us-east-1', 'lightsail'))->sign('POST', 'https://lightsail.us-east-1.amazonaws.com/', [
            'Content-Type' => 'application/x-amz-json-1.1',
            'X-Amz-Target' => 'Lightsail_20161128.GetRegions',
        ], $body, Carbon::now());

        return isLightsailOp($r, 'GetRegions')
            && $r->header('X-Amz-Date')[0] === '20260926T100000Z'
            && $r->header('Authorization')[0] === $expected['Authorization']
            && str_contains($expected['Authorization'], 'Credential=AKIAEXAMPLE/20260926/us-east-1/lightsail/aws4_request')
            && json_decode($body, true) === ['includeAvailabilityZones' => false];
    });
});

it('maps AWS JSON errors', function () {
    Http::fake(['*' => Http::response(['__type' => 'UnrecognizedClientException', 'message' => 'The security token included in the request is invalid.'], 400)]);

    try {
        lightsail()->verify();
        $this->fail('expected exception');
    } catch (ProviderException $e) {
        expect($e->getMessage())->toBe('AWS Lightsail: UnrecognizedClientException: The security token included in the request is invalid.')
            ->and($e->provider)->toBe('aws')
            ->and($e->status)->toBe(400);
    }
});

it('lists regions', function () {
    Http::fake(['*' => Http::response(['regions' => [['name' => 'eu-central-1', 'displayName' => 'Frankfurt'], ['name' => 'us-east-1', 'displayName' => 'Virginia']]])]);

    $regions = lightsail()->regions();

    expect($regions)->toHaveCount(2)
        ->and($regions[0]->id)->toBe('eu-central-1')
        ->and($regions[0]->name)->toBe('Frankfurt (eu-central-1)');
});

it('lists active Linux bundles from the requested region, following nextPageToken', function () {
    Http::fake(['lightsail.eu-central-1.amazonaws.com/*' => Http::sequence()
        ->push([
            'bundles' => [
                ['bundleId' => 'nano_3_0', 'name' => 'Nano', 'cpuCount' => 2, 'ramSizeInGb' => 0.5, 'diskSizeInGb' => 20, 'price' => 3.5, 'isActive' => true, 'supportedPlatforms' => ['LINUX_UNIX']],
                ['bundleId' => 'nano_win_3_0', 'name' => 'Nano', 'cpuCount' => 2, 'ramSizeInGb' => 0.5, 'diskSizeInGb' => 30, 'price' => 8, 'isActive' => true, 'supportedPlatforms' => ['WINDOWS']],
            ],
            'nextPageToken' => 'tok-2',
        ])
        ->push([
            'bundles' => [['bundleId' => 'small_3_0', 'name' => 'Small', 'cpuCount' => 2, 'ramSizeInGb' => 2.0, 'diskSizeInGb' => 60, 'price' => 12, 'isActive' => true, 'supportedPlatforms' => ['LINUX_UNIX']]],
        ]),
    ]);

    $sizes = lightsail()->sizes('eu-central-1');

    expect(array_map(fn ($s) => $s->id, $sizes))->toBe(['nano_3_0', 'small_3_0'])
        ->and($sizes[0]->memoryMb)->toBe(512)
        ->and($sizes[0]->regions)->toBe(['eu-central-1']);

    Http::assertSent(fn (Request $r) => isLightsailOp($r, 'GetBundles', 'eu-central-1') && json_decode($r->body(), true) === ['includeInactive' => false, 'pageToken' => 'tok-2']);
});

it('lists Ubuntu LTS blueprints', function () {
    Http::fake(['*' => Http::response(['blueprints' => [
        ['blueprintId' => 'ubuntu_24_04', 'name' => 'Ubuntu', 'version' => '24.04 LTS', 'type' => 'os', 'isActive' => true],
        ['blueprintId' => 'ubuntu_22_04', 'name' => 'Ubuntu', 'version' => '22.04 LTS', 'type' => 'os', 'isActive' => true],
        ['blueprintId' => 'wordpress', 'name' => 'WordPress', 'version' => '6', 'type' => 'app', 'isActive' => true],
    ]])]);

    $images = lightsail()->images();

    expect(array_map(fn ($i) => $i->id, $images))->toBe(['ubuntu_24_04', 'ubuntu_22_04'])
        ->and($images[0]->name)->toBe('Ubuntu 24.04 LTS');
});

it('creates instances in the target region with user data, same-region key pair and tags', function () {
    Http::fake(['lightsail.eu-central-1.amazonaws.com/*' => Http::response(['operations' => [['id' => 'op-1', 'status' => 'Started']]])]);

    $machine = lightsail()->createServer(new MachineSpec(
        name: 'web 1',
        region: 'eu-central-1',
        size: 'small_3_0',
        image: 'ubuntu_24_04',
        sshKeyIds: ['us-east-1:other-key', 'eu-central-1:dev-abc123'],
        userData: "#!/bin/sh\necho hi",
        labels: ['falak-server' => '01J'],
    ));

    expect($machine->id)->toBe('eu-central-1:web-1')
        ->and($machine->status)->toBe(Machine::STATUS_PROVISIONING);

    Http::assertSent(fn (Request $r) => isLightsailOp($r, 'CreateInstances', 'eu-central-1') && json_decode($r->body(), true) === [
        'instanceNames' => ['web-1'],
        'availabilityZone' => 'eu-central-1a',
        'blueprintId' => 'ubuntu_24_04',
        'bundleId' => 'small_3_0',
        'userData' => "#!/bin/sh\necho hi",
        'keyPairName' => 'dev-abc123',
        'ipAddressType' => 'dualstack',
        'tags' => [['key' => 'falak-server', 'value' => '01J']],
    ]);
});

it('does not retry CreateInstances on server errors', function () {
    Http::fake(['*' => Http::response(['__type' => 'ServiceException', 'message' => 'boom'], 500)]);

    expect(fn () => lightsail()->createServer(new MachineSpec('a', 'us-east-1', 'nano_3_0', 'ubuntu_24_04')))->toThrow(ProviderException::class);
    Http::assertSentCount(1);
});

it('reads instances and returns null when they are gone', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['instance' => [
            'name' => 'web-1', 'state' => ['name' => 'running'], 'publicIpAddress' => '3.120.0.1', 'privateIpAddress' => '172.26.0.5',
            'ipv6Addresses' => ['2a05:d014::1'], 'location' => ['regionName' => 'eu-central-1'],
        ]])
        ->push(['__type' => 'NotFoundException', 'message' => 'The Instance does not exist: web-2'], 400),
    ]);

    $machine = lightsail()->getServer('eu-central-1:web-1');

    expect($machine?->status)->toBe(Machine::STATUS_RUNNING)
        ->and($machine?->ipv4)->toBe('3.120.0.1')
        ->and($machine?->ipv6)->toBe('2a05:d014::1')
        ->and($machine?->privateIpv4)->toBe('172.26.0.5')
        ->and(lightsail()->getServer('eu-central-1:web-2'))->toBeNull();

    Http::assertSent(fn (Request $r) => isLightsailOp($r, 'GetInstance', 'eu-central-1') && json_decode($r->body(), true) === ['instanceName' => 'web-1']);
});

it('deletes instances idempotently', function () {
    Http::fake(['*' => Http::response(['__type' => 'NotFoundException', 'message' => 'gone'], 400)]);

    lightsail()->destroyServer('eu-central-1:web-1');

    Http::assertSent(fn (Request $r) => isLightsailOp($r, 'DeleteInstance', 'eu-central-1') && json_decode($r->body(), true) === ['instanceName' => 'web-1', 'forceDeleteAddOns' => true]);
});

it('imports key pairs once under a deterministic name', function () {
    $expectedName = 'dev-'.substr(hash('sha256', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV'), 0, 12);

    Http::fake(['*' => Http::sequence()
        ->push(['keyPairs' => []])
        ->push(['operation' => ['status' => 'Succeeded']])
        ->push(['keyPairs' => [['name' => $expectedName]]]),
    ]);

    expect(lightsail()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe("us-east-1:{$expectedName}")
        ->and(lightsail()->uploadSshKey('dev', PROVIDERS_TEST_KEY))->toBe("us-east-1:{$expectedName}");

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => isLightsailOp($r, 'ImportKeyPair') && json_decode($r->body(), true) === [
        'keyPairName' => $expectedName,
        'publicKeyBase64' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAILhfLAQI6GIEE8z6YcLPiiVBw4Tu9QD2miHt6Q8acbUV',
    ]);
});

it('deletes key pairs in their region', function () {
    Http::fake(['*' => Http::response(['operation' => []])]);

    lightsail()->deleteSshKey('eu-west-1:dev-abc');

    Http::assertSent(fn (Request $r) => isLightsailOp($r, 'DeleteKeyPair', 'eu-west-1') && json_decode($r->body(), true) === ['keyPairName' => 'dev-abc']);
});
