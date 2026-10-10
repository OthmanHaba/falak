<?php

use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\RestoreFinished;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\Role;
use Falak\Previews\Application\PreviewLifecycle;
use Falak\Previews\Domain\Models\Preview;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

/*
 * Security review fixes: databases must be ready before anything deploys, production copies never outlive their
 * setup, data exposure needs the project's acknowledgement, public messages stay generic, TTL for waiting forks.
 */

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->sc = sites_fake_source_control();
    $this->connection = $this->sc->addConnection($this->organization->id);
    ['domains' => $this->domains, 'databases' => $this->databases, 'deployments' => $this->deployments] = previews_fakes();
    $this->server = sites_server($this->organization->id);
    $this->production = projects_default_env($this->organization);
    $this->staging = projects_environment($this->organization, 'staging');
    $this->web = projects_site($this->organization, 'web', [], $this->staging, [$this->server], ['source_connection_id' => $this->connection->id, 'repository' => 'acme/shop']);
    projects_database($this->organization, 'db', $this->staging);
    projects_database($this->organization, 'db', $this->production);
    $this->settings = previews_settings($this->organization, $this->staging->project_id, $this->staging->id);
});

function hard_open(object $test, int $number = 7, string $sha = 'c'): Preview
{
    PullRequestOpened::dispatch($test->organization->id, $test->connection->id, 'github', previews_pr($number, $sha));

    return Preview::query()->where('number', $number)->sole();
}

it('never deploys while a database is not ready, whatever triggers it', function () {
    $preview = hard_open($this);
    expect($preview->databases['db']['state'])->toBe('creating');

    // A redeploy or a new head while the database is still being created: nothing deploys.
    expect(fn () => app(PreviewLifecycle::class)->redeploy($preview))->toThrow(ValidationException::class);
    PullRequestUpdated::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(sha: 'd'));

    expect($this->deployments->deployed)->toBe([]);
});

it('drops a production copy that is still restoring or sanitizing when the preview fails or leaves its setup', function () {
    $this->settings->forceFill(['databases' => ['db' => ['strategy' => PreviewSettings::CLONE_SANITIZE, 'sanitize_kind' => 'sql', 'sanitize_script' => 'DELETE FROM users;']]])->save();
    $preview = hard_open($this);
    $databaseId = $preview->databases['db']['database_id'];
    DatabaseCreated::dispatch($databaseId, $this->organization->id, $this->server->id, 'db', 'postgresql', null);

    // A deployment failure elsewhere (or any failure) while the copy restores: the copy goes now.
    $preview->refresh()->forceFill(['deployments' => [$this->web->id => 'deploying']])->save();
    DeploymentFailed::dispatch('d', $this->organization->id, $preview->sites['web'], 'web', 1, 'manual', 'build', 'boom', null, false);
    app(PreviewLifecycle::class)->redeploy($preview->refresh()->forceFill(['status' => Preview::FAILED]));

    expect($this->databases->deleted)->toBe([['id' => $databaseId, 'volume' => true]])
        ->and($preview->refresh()->databases['db']['state'])->toBe('dropped')
        ->and($this->deployments->deployed)->toBe([]);

    // The restore finishes late: nothing comes back to life, and the sanitize script never runs on it.
    RestoreFinished::dispatch($this->databases->restores[0]['id'], $this->organization->id, 'b', $this->server->id, 'db', true, null);
    expect($this->databases->scripts)->toBe([])->and($this->deployments->deployed)->toBe([]);
});

it('drops a copy whose sanitize script finishes after the preview failed', function () {
    $this->settings->forceFill(['databases' => ['db' => ['strategy' => PreviewSettings::CLONE_SANITIZE, 'sanitize_kind' => 'sql', 'sanitize_script' => 'DELETE FROM users;']]])->save();
    $preview = hard_open($this);
    $databaseId = $preview->databases['db']['database_id'];
    DatabaseCreated::dispatch($databaseId, $this->organization->id, $this->server->id, 'db', 'postgresql', null);
    RestoreFinished::dispatch($this->databases->restores[0]['id'], $this->organization->id, 'b', $this->server->id, 'db', true, null);
    $key = $this->databases->scripts[0]['key'];

    // Something else failed the preview in between (the copy is sanitizing, so it goes at once).
    $preview->refresh()->forceFill(['status' => Preview::FAILED])->save();
    CommandFinished::dispatch('cmd', $this->organization->id, $this->server->id, 'system.exec', $key, 0, []);

    expect($this->databases->deleted)->toBe([['id' => $databaseId, 'volume' => true]])->and($this->deployments->deployed)->toBe([]);
});

it('records each preview database as soon as it exists, so a later failure deletes it', function () {
    projects_database($this->organization, 'cache', $this->staging, 'redis');
    $this->domains->settings = null; // routing fails after the databases were created

    $preview = hard_open($this);
    $preview->refresh();
    expect($preview->status)->toBe(Preview::FAILED)->and($preview->databases)->toHaveCount(2);

    app(PreviewLifecycle::class)->destroy($preview, 'Deleted in Falak.');
    expect(collect($this->databases->deleted)->pluck('id')->sort()->values()->all())
        ->toBe(collect($this->databases->created)->keys()->sort()->values()->all());
});

it('keeps errors out of the pull request: the comment and status say only that it failed', function () {
    $this->databases->noBackup = true;
    $this->settings->forceFill(['databases' => ['db' => ['strategy' => PreviewSettings::CLONE_BACKUP]]])->save();
    $preview = hard_open($this);
    DatabaseCreated::dispatch($preview->databases['db']['database_id'], $this->organization->id, $this->server->id, 'db', 'postgresql', null);

    expect($preview->refresh()->status)->toBe(Preview::FAILED)->and($preview->status_message)->toContain('no successful backup');
    $comment = array_values($this->sc->comments)[0]['body'];
    expect($comment)->toContain('failed')->not->toContain('backup')->not->toContain($this->server->name)
        ->and(collect($this->sc->statuses)->last()['description'])->toBe('Preview failed');
});

it('closes previews waiting for an approval once idle past the TTL; a fork\'s pushes keep nothing alive', function () {
    $preview = hard_open($this);
    $preview->forceFill(['status' => Preview::WAITING_APPROVAL, 'is_fork' => true])->save();

    Carbon::setTestNow(now()->addHours(40));
    PullRequestUpdated::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(sha: 'e', fork: true));
    expect($preview->refresh()->last_activity_at->lt(now()->subHours(39)))->toBeTrue();

    Carbon::setTestNow(now()->addHours(40));
    expect(app(PreviewLifecycle::class)->cleanupIdle())->toBe(1)->and($preview->refresh()->status)->toBe(Preview::CLOSED);
    Carbon::setTestNow();
});

it('asks the project to acknowledge sharing the base database and cloning production unsanitized', function () {
    $project = $this->staging->project_id;
    $base = ['enabled' => true, 'base_environment_id' => $this->staging->id];

    $this->put("/projects/{$project}/previews/settings", [...$base, 'services' => ['db' => 'share']])->assertSessionHasErrors('acknowledge_shared_database');
    $this->put("/projects/{$project}/previews/settings", [...$base, 'services' => ['db' => 'share'], 'acknowledge_shared_database' => true])->assertSessionHasNoErrors();

    $clone = ['strategy' => 'clone_backup', 'source_environment_id' => $this->production->id];
    $this->put("/projects/{$project}/previews/settings", [...$base, 'databases' => ['db' => $clone]])->assertSessionHasErrors('databases.db.acknowledge_production');
    $this->put("/projects/{$project}/previews/settings", [...$base, 'databases' => ['db' => [...$clone, 'acknowledge_production' => true]]])->assertSessionHasNoErrors();
    // Staging needs no acknowledgement.
    $this->put("/projects/{$project}/previews/settings", [...$base, 'databases' => ['db' => ['strategy' => 'clone_backup']]])->assertSessionHasNoErrors();
});

it('refuses a fork server that hosts other sites, at save time', function () {
    $this->put("/projects/{$this->staging->project_id}/previews/settings", ['enabled' => true, 'base_environment_id' => $this->staging->id, 'fork_server_id' => $this->server->id])
        ->assertSessionHasErrors('fork_server_id');

    $dedicated = sites_server($this->organization->id, docker: true);
    $this->put("/projects/{$this->staging->project_id}/previews/settings", ['enabled' => true, 'base_environment_id' => $this->staging->id, 'fork_server_id' => $dedicated->id])
        ->assertSessionHasNoErrors();
});
