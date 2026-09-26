<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderGateway;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Providers\Domain\Models\ProviderCredential;
use Kiln\Providers\Infrastructure\Adapters\HetznerAdapter;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['providers.http.retry_sleep_ms' => 0]);
});

function hetznerLocationsFake(): void
{
    Http::fake(['api.hetzner.cloud/v1/locations*' => Http::response([
        'locations' => [['id' => 1, 'name' => 'fsn1', 'description' => 'Falkenstein DC Park 1', 'country' => 'DE', 'city' => 'Falkenstein']],
        'meta' => ['pagination' => ['next_page' => null]],
    ])]);
}

it('lists and resolves credentials scoped to the organization', function () {
    $mine = ProviderCredential::factory()->forOrganization('01JORGAAAAAAAAAAAAAAAAAAAA')->create(['name' => 'B']);
    ProviderCredential::factory()->forOrganization('01JORGAAAAAAAAAAAAAAAAAAAA')->create(['name' => 'A']);
    $theirs = ProviderCredential::factory()->forOrganization('01JORGBBBBBBBBBBBBBBBBBBBB')->create();

    $gateway = app(ProviderGateway::class);

    expect(array_map(fn ($c) => $c->name, $gateway->credentials('01JORGAAAAAAAAAAAAAAAAAAAA')))->toBe(['A', 'B'])
        ->and($gateway->credential('01JORGAAAAAAAAAAAAAAAAAAAA', $mine->id)?->provider)->toBe(ProviderType::Hetzner)
        ->and($gateway->credential('01JORGAAAAAAAAAAAAAAAAAAAA', $theirs->id))->toBeNull()
        ->and($gateway->adapter('01JORGAAAAAAAAAAAAAAAAAAAA', $mine->id))->toBeInstanceOf(HetznerAdapter::class)
        ->and(fn () => $gateway->adapter('01JORGAAAAAAAAAAAAAAAAAAAA', $theirs->id))->toThrow(ProviderException::class)
        ->and(fn () => $gateway->regions('01JORGAAAAAAAAAAAAAAAAAAAA', $theirs->id))->toThrow(ProviderException::class);
});

it('caches catalog calls per credential and invalidates on update', function () {
    hetznerLocationsFake();
    $credential = ProviderCredential::factory()->forOrganization('01JORGAAAAAAAAAAAAAAAAAAAA')->create();
    $gateway = app(ProviderGateway::class);

    $gateway->regions('01JORGAAAAAAAAAAAAAAAAAAAA', $credential->id);
    $regions = $gateway->regions('01JORGAAAAAAAAAAAAAAAAAAAA', $credential->id);

    expect($regions[0]->id)->toBe('fsn1');
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    $credential->update(['name' => 'renamed']);
    $gateway->regions('01JORGAAAAAAAAAAAAAAAAAAAA', $credential->id);

    Http::assertSentCount(2);
});

it('caches sizes per region', function () {
    Http::fake(['api.hetzner.cloud/v1/server_types*' => Http::response(['server_types' => [], 'meta' => ['pagination' => ['next_page' => null]]])]);
    $credential = ProviderCredential::factory()->forOrganization('01JORGAAAAAAAAAAAAAAAAAAAA')->create();
    $gateway = app(ProviderGateway::class);

    $gateway->sizes('01JORGAAAAAAAAAAAAAAAAAAAA', $credential->id, 'fsn1');
    $gateway->sizes('01JORGAAAAAAAAAAAAAAAAAAAA', $credential->id, 'fsn1');
    $gateway->sizes('01JORGAAAAAAAAAAAAAAAAAAAA', $credential->id, 'nbg1');

    Http::assertSentCount(2);
});

it('stores credentials encrypted at rest', function () {
    $credential = ProviderCredential::factory()->create(['credentials' => ['token' => 'super-secret-token']]);

    $raw = (string) DB::table('providers_credentials')->where('id', $credential->id)->value('credentials');

    expect($raw)->not->toContain('super-secret-token')
        ->and($credential->fresh()?->credentials)->toBe(['token' => 'super-secret-token'])
        ->and($credential->toArray())->not->toHaveKey('credentials');
});
