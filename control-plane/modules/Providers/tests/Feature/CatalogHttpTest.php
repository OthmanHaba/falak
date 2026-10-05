<?php

use Illuminate\Support\Facades\Http;
use Falak\Identity\Contracts\Role;
use Falak\Providers\Domain\Models\ProviderCredential;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['providers.http.retry_sleep_ms' => 0]);
});

it('serves regions, sizes and images as JSON for viewers', function () {
    [, $org] = actingAsMember(Role::Viewer);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create();

    Http::fake([
        'api.hetzner.cloud/v1/locations*' => Http::response([
            'locations' => [['id' => 1, 'name' => 'fsn1', 'description' => 'Falkenstein DC Park 1', 'country' => 'DE', 'city' => 'Falkenstein']],
            'meta' => ['pagination' => ['next_page' => null]],
        ]),
        'api.hetzner.cloud/v1/server_types*' => Http::response([
            'server_types' => [['id' => 1, 'name' => 'cx22', 'description' => 'CX22', 'cores' => 2, 'memory' => 4, 'disk' => 40, 'architecture' => 'x86',
                'prices' => [['location' => 'fsn1', 'price_monthly' => ['gross' => '4.59']]]]],
            'meta' => ['pagination' => ['next_page' => null]],
        ]),
        'api.hetzner.cloud/v1/images*' => Http::response([
            'images' => [['id' => 5, 'name' => 'ubuntu-24.04', 'description' => 'Ubuntu 24.04', 'os_flavor' => 'ubuntu', 'os_version' => '24.04', 'architecture' => 'x86']],
            'meta' => ['pagination' => ['next_page' => null]],
        ]),
    ]);

    $this->getJson("/providers/{$credential->id}/regions")->assertOk()
        ->assertExactJson(['data' => [['id' => 'fsn1', 'name' => 'Falkenstein (Falkenstein DC Park 1)', 'country' => 'DE', 'available' => true]]]);

    $this->getJson("/providers/{$credential->id}/sizes?region=fsn1")->assertOk()
        ->assertJsonPath('data.0.id', 'cx22')
        ->assertJsonPath('data.0.memory_mb', 4096)
        ->assertJsonPath('data.0.price_monthly', 4.59);

    $this->getJson("/providers/{$credential->id}/images")->assertOk()
        ->assertJsonPath('data.0.id', '5')
        ->assertJsonPath('data.0.version', '24.04');
});

it('returns 502 with the provider message when the provider fails', function () {
    [, $org] = actingAsMember(Role::Developer);
    $credential = ProviderCredential::factory()->forOrganization($org->id)->create(['credentials' => ['token' => 'secret-token']]);
    Http::fake(['*' => Http::response(['error' => ['message' => 'unable to authenticate']], 401)]);

    $response = $this->getJson("/providers/{$credential->id}/regions")->assertStatus(502)
        ->assertJson(['message' => 'Hetzner Cloud: unable to authenticate']);

    expect($response->getContent())->not->toContain('secret-token');
});

it('requires the providers.view permission', function () {
    $this->getJson('/providers/01JNOPEAAAAAAAAAAAAAAAAAAA/regions')->assertUnauthorized();
});
