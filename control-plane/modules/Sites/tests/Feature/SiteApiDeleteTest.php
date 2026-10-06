<?php

use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Identity\Contracts\Role;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Events\SiteDeleted;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->admin, $this->organization] = actingAsMember(Role::Admin);
    $this->agents = sites_fake_agents();
    sites_fake_source_control();
    $this->server = sites_server($this->organization->id, ['name' => 'docker-1'], docker: true);

    $this->post('/sites', sites_input([$this->server->id], ['name' => 'Api', 'framework' => 'docker', 'runtime' => 'docker', 'php_version' => null, 'docker_image' => 'ghcr.io/acme/api:1']))
        ->assertSessionHasNoErrors();
    $this->site = Site::query()->firstOrFail();

    // From here on requests authenticate with API tokens only.
    app('auth')->forgetGuards();
});

function site_api_token(object $user, string $organizationId, array $abilities): string
{
    return app(CreateApiToken::class)($user, $organizationId, 'cli', $abilities)->plainTextToken;
}

it('deletes a site by slug and stops its containers on the servers (202, async like DELETE /servers)', function () {
    Event::fake([SiteDeleted::class]);
    $token = site_api_token($this->admin, $this->organization->id, ['sites.view', 'sites.delete']);

    $this->withToken($token)->deleteJson('/api/v1/sites/api')->assertStatus(202)->assertNoContent(202);

    expect(Site::query()->find($this->site->id))->toBeNull();
    $stops = $this->agents->ofType('docker.stop');
    expect(array_column(array_column($stops, 'payload'), 'name'))->toBe(['falak-api-blue', 'falak-api-green'])
        ->and(array_unique(array_column($stops, 'server_id')))->toBe([$this->server->id]);
    Event::assertDispatched(SiteDeleted::class, fn (SiteDeleted $event) => $event->siteId === $this->site->id);
});

it('deletes a site by id and accepts delete_volumes (volume ids)', function () {
    $token = site_api_token($this->admin, $this->organization->id, ['*']);

    $this->withToken($token)->deleteJson('/api/v1/sites/'.strtoupper($this->site->id), ['delete_volumes' => ['01j9z8y7x6w5v4t3s2r1q0p9na']])
        ->assertUnprocessable()->assertJsonValidationErrors(['delete_volumes']);
    $this->withToken($token)->deleteJson('/api/v1/sites/'.strtoupper($this->site->id), ['delete_volumes' => []])->assertStatus(202);

    expect(Site::query()->count())->toBe(0);
});

it('validates delete_volumes', function () {
    $token = site_api_token($this->admin, $this->organization->id, ['sites.view', 'sites.delete']);

    $this->withToken($token)->deleteJson('/api/v1/sites/api', ['delete_volumes' => 'maybe'])
        ->assertUnprocessable()->assertJsonValidationErrors(['delete_volumes']);

    expect(Site::query()->count())->toBe(1);
});

it('requires the sites.delete ability on the token', function () {
    $token = site_api_token($this->admin, $this->organization->id, ['sites.view', 'sites.manage']);

    $this->withToken($token)->deleteJson('/api/v1/sites/api')->assertForbidden()->assertJsonStructure(['message']);

    expect(Site::query()->count())->toBe(1)->and($this->agents->ofType('docker.stop'))->toBe([]);
});

it('requires a role that may delete sites', function () {
    [$developer] = memberOf($this->organization, Role::Developer);
    $token = site_api_token($developer, $this->organization->id, ['*']);

    $this->withToken($token)->deleteJson('/api/v1/sites/api')->assertForbidden();

    expect(Site::query()->count())->toBe(1);
});

it('does not reveal sites of other organizations', function () {
    [$owner, $other] = memberOf(role: Role::Owner);
    $token = site_api_token($owner, $other->id, ['*']);

    $this->withToken($token)->deleteJson("/api/v1/sites/{$this->site->id}")->assertNotFound()->assertJsonStructure(['message']);

    expect(Site::query()->count())->toBe(1);
});

it('needs a token', function () {
    $this->deleteJson('/api/v1/sites/api')->assertUnauthorized();

    expect(Site::query()->count())->toBe(1);
});
