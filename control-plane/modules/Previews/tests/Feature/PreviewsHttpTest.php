<?php

use Falak\Identity\Contracts\Role;
use Falak\Previews\Domain\Models\Preview;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\SourceControl\Events\PullRequestOpened;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    // The first organization operates the instance.
    [$this->owner, $this->organization] = actingAsMember(Role::Owner);
    $this->agents = sites_fake_agents();
    $this->sc = sites_fake_source_control();
    $this->connection = $this->sc->addConnection($this->organization->id);
    ['domains' => $this->domains, 'deployments' => $this->deployments] = previews_fakes();
    $this->server = sites_server($this->organization->id);
    $this->staging = projects_environment($this->organization, 'staging');
    $this->web = projects_site($this->organization, 'web', [], $this->staging, [$this->server], ['source_connection_id' => $this->connection->id, 'repository' => 'acme/shop']);
    $this->projectId = $this->staging->project_id;
});

function http_open_preview(object $test, bool $fork = false): Preview
{
    previews_settings($test->organization, $test->projectId, $test->staging->id);
    PullRequestOpened::dispatch($test->organization->id, $test->connection->id, 'github', previews_pr(fork: $fork));

    return previews_sole();
}

it('shows the Previews tab with the credentials to members who can view them', function () {
    $preview = http_open_preview($this);
    [$viewer] = memberOf($this->organization, Role::Viewer);

    $this->actingAs($viewer)->get("/projects/{$this->projectId}/previews")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Previews/Index', false)
        ->where('previews.0.number', 7)
        ->where('previews.0.urls.web', 'https://pr-7-web.prv.example.com')
        ->where('previews.0.credentials.username', 'preview')
        ->where('previews.0.credentials.password', $preview->basic_password)
        ->where('can.manage', false));
});

it('hides projects and previews of other organizations', function () {
    $preview = http_open_preview($this);
    [$stranger] = memberOf(null, Role::Owner);
    $this->actingAs($stranger);

    $this->get("/projects/{$this->projectId}/previews")->assertNotFound();
    $this->put("/projects/{$this->projectId}/previews/settings", ['enabled' => false])->assertNotFound();
    $this->post("/previews/{$preview->id}/redeploy")->assertNotFound();
    $this->delete("/previews/{$preview->id}")->assertNotFound();
    expect($preview->refresh()->status)->not->toBe(Preview::CLOSED);
});

it('lets viewers look but not change anything', function () {
    $preview = http_open_preview($this, fork: true);
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer);

    $this->put("/projects/{$this->projectId}/previews/settings", ['enabled' => false])->assertForbidden();
    $this->post("/previews/{$preview->id}/approve")->assertForbidden();
    $this->delete("/previews/{$preview->id}")->assertForbidden();
    expect($preview->refresh()->status)->toBe(Preview::WAITING_APPROVAL);
});

it('approves a fork, redeploys and deletes from the UI', function () {
    $preview = http_open_preview($this, fork: true);
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);

    $this->post("/previews/{$preview->id}/approve")->assertRedirect()->assertSessionHasNoErrors();
    expect($preview->refresh()->approved_by)->toBe($developer->id)->and($preview->environment_id)->not->toBeNull();

    $preview->forceFill(['status' => Preview::READY, 'deployments' => []])->save();
    $this->post("/previews/{$preview->id}/redeploy")->assertRedirect()->assertSessionHasNoErrors();
    expect($this->deployments->deployed)->not->toBe([]);

    $this->delete("/previews/{$preview->id}")->assertRedirect();
    expect($preview->refresh()->status)->toBe(Preview::CLOSED);
});

it('saves settings, validates them and pins the base repositories\' webhooks', function () {
    $this->put("/projects/{$this->projectId}/previews/settings", [
        'enabled' => true,
        'base_environment_id' => $this->staging->id,
        'domain_pattern' => 'pr-{number}-{service}-{project}',
        'max_concurrent' => 3,
        'access' => 'public',
        'databases' => ['db' => ['strategy' => 'clone_sanitize', 'sanitize_kind' => 'sql', 'sanitize_script' => 'DELETE FROM sessions;']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $settings = PreviewSettings::query()->sole();
    expect($settings->enabled)->toBeTrue()->and($settings->max_concurrent)->toBe(3)->and($settings->access)->toBe('public')
        ->and($this->sc->pinned)->toBe(["{$this->connection->id}|acme/shop" => true]);

    $this->put("/projects/{$this->projectId}/previews/settings", ['enabled' => true, 'base_environment_id' => $this->staging->id, 'domain_pattern' => 'pr-{number}'])
        ->assertSessionHasErrors('domain_pattern');
    $this->put("/projects/{$this->projectId}/previews/settings", ['enabled' => true, 'base_environment_id' => $this->staging->id, 'databases' => ['db' => ['strategy' => 'clone_sanitize']]])
        ->assertSessionHasErrors('databases.db.sanitize_script');
    $this->put("/projects/{$this->projectId}/previews/settings", ['enabled' => true, 'base_environment_id' => $this->staging->id, 'max_concurrent' => 500])
        ->assertSessionHasErrors('max_concurrent');

    [, $other] = memberOf(null, Role::Owner);
    $theirs = sites_server($other->id);
    $this->put("/projects/{$this->projectId}/previews/settings", ['enabled' => true, 'base_environment_id' => $this->staging->id, 'server_id' => $theirs->id])
        ->assertSessionHasErrors('server_id');
});

it('lets only the operator organization\'s owners and admins set the preview domain', function () {
    config(['previews.operator_organization' => $this->organization->slug]);
    $this->get('/settings/previews')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Previews/Domain', false)->where('can.manage', true));
    $this->put('/settings/previews', ['domain' => 'prv.falak.sh', 'server_id' => $this->server->id])->assertRedirect()->assertSessionHasNoErrors();
    expect($this->domains->settings->domain)->toBe('prv.falak.sh');

    // A developer of the operator, and the owner of another organization: read only.
    [$developer] = memberOf($this->organization, Role::Developer);
    [$customer] = memberOf(null, Role::Owner);

    foreach ([$developer, $customer] as $user) {
        $this->actingAs($user)->get('/settings/previews')->assertOk()->assertInertia(fn (Assert $page) => $page->where('can.manage', false)->where('settings.server_id', null));
        $this->actingAs($user)->put('/settings/previews', ['domain' => 'evil.example.com', 'server_id' => $this->server->id])->assertForbidden();
        $this->actingAs($user)->delete('/settings/previews')->assertForbidden();
    }

    expect($this->domains->settings->domain)->toBe('prv.falak.sh');

    // Several organizations and none recorded: nobody operates the preview domain.
    config(['previews.operator_organization' => '']);
    $this->actingAs($this->owner)->put('/settings/previews', ['domain' => 'prv.falak.sh', 'server_id' => $this->server->id])->assertForbidden();
});
