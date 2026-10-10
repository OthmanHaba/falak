<?php

use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\RestoreFinished;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\Role;
use Falak\Previews\Application\PreviewLifecycle;
use Falak\Previews\Domain\Models\Preview;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Projects\Domain\Models\Environment;
use Falak\Projects\Domain\Models\Service;
use Falak\Secrets\Application\Actions\CreateSecret;
use Falak\Secrets\Contracts\SecretScope;
use Falak\Sites\Domain\Models\EnvironmentVersion;
use Falak\Sites\Domain\Models\Site;
use Falak\SourceControl\Events\PullRequestClosed;
use Falak\SourceControl\Events\PullRequestCommented;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    $this->agents = sites_fake_agents();
    $this->sc = sites_fake_source_control();
    $this->connection = $this->sc->addConnection($this->organization->id);
    ['domains' => $this->domains, 'databases' => $this->databases, 'deployments' => $this->deployments] = previews_fakes();
    $this->server = sites_server($this->organization->id);
    $this->production = projects_default_env($this->organization);
    $this->staging = projects_environment($this->organization, 'staging');
    $this->web = projects_site($this->organization, 'web', ['APP_KEY' => 'base64:staging', 'STRIPE_SECRET' => 'sk_live_x', 'DATABASE_URL' => '${{ db.DATABASE_URL }}'], $this->staging, [$this->server], [
        'source_connection_id' => $this->connection->id, 'repository' => 'acme/shop', 'branch' => 'main',
    ]);
    [$this->stagingDb] = projects_database($this->organization, 'db', $this->staging);
    $this->settings = previews_settings($this->organization, $this->staging->project_id, $this->staging->id);
});

function open_pr(object $test, ...$args): void
{
    PullRequestOpened::dispatch($test->organization->id, $test->connection->id, 'github', previews_pr(...$args));
}

/** Announce every database a preview waits for, and succeed every deployment. */
function preview_go_live(object $test, Preview $preview): Preview
{
    foreach ($preview->refresh()->databases ?? [] as $entry) {
        DatabaseCreated::dispatch($entry['database_id'], $test->organization->id, $test->server->id, 'db', 'postgresql', null);
    }

    foreach ($preview->refresh()->sites ?? [] as $siteId) {
        DeploymentSucceeded::dispatch('dep', $test->organization->id, $siteId, 'manual', $preview->head_sha, 'rel', [$test->server->id], 10);
    }

    return $preview->refresh();
}

it('forks the base environment, creates the database, routes behind basic auth, deploys the head and comments the URLs', function () {
    open_pr($this);

    $preview = previews_sole();
    expect($preview->status)->toBe(Preview::CREATING);
    $environment = Environment::query()->findOrFail($preview->environment_id);
    expect($environment->is_preview)->toBeTrue()->and($environment->is_fork_preview)->toBeFalse()
        ->and($environment->forked_from_id)->toBe($this->staging->id)
        ->and($environment->name)->toBe('PR #7');

    $copy = Site::query()->findOrFail($preview->sites['web']);
    expect($copy->branch)->toBe('feature/checkout')
        ->and($copy->push_to_deploy)->toBeFalse()
        ->and($copy->targets->pluck('server_id')->all())->toBe([$this->server->id]);

    // The database: created on the preview server, in the preview's network, placed under the base's name.
    $created = array_values($this->databases->created)[0];
    expect($created['engine'])->toBe('postgresql')->and($created['server'])->toBe($this->server->id)
        ->and($created['options']['environment_id'])->toBe($environment->id)
        ->and(Service::query()->where('environment_id', $environment->id)->where('kind', 'database')->value('name'))->toBe('db');

    expect($this->domains->routes)->toBe(['pr-7-web.prv.example.com' => $copy->id])
        ->and($this->domains->protected[$copy->id]['username'])->toBe('preview')
        ->and(strlen($this->domains->protected[$copy->id]['password']))->toBe(24)
        ->and($this->deployments->deployed)->toBe([]);

    $preview = preview_go_live($this, $preview);

    expect($this->deployments->deployed)->toHaveCount(1)
        ->and($this->deployments->deployed[0])->toMatchArray(['site_id' => $copy->id, 'commit' => str_repeat('c', 40)])
        ->and($preview->status)->toBe(Preview::READY)
        ->and($preview->urls)->toBe(['web' => 'https://pr-7-web.prv.example.com']);

    // One comment, edited along the way; the password never appears in it.
    expect($this->sc->comments)->toHaveCount(1);
    $comment = array_values($this->sc->comments)[0]['body'];
    expect($comment)->toContain('https://pr-7-web.prv.example.com')->toContain('ready')->toContain('credentials in Falak')
        ->not->toContain($preview->basic_password);
    expect(collect($this->sc->statuses)->last())->toMatchArray(['state' => 'success', 'context' => 'falak/preview', 'sha' => str_repeat('c', 40)]);
});

it('redeploys on a new head and tears everything down when the pull request closes', function () {
    open_pr($this);
    $preview = preview_go_live($this, previews_sole());
    $siteId = $preview->sites['web'];
    $databaseId = $preview->databases['db']['database_id'];

    PullRequestUpdated::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(sha: 'd'));
    expect($this->deployments->deployed)->toHaveCount(2)
        ->and($this->deployments->deployed[1]['commit'])->toBe(str_repeat('d', 40))
        ->and($preview->refresh()->status)->toBe(Preview::DEPLOYING);

    PullRequestClosed::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(sha: 'd'), true);

    $preview->refresh();
    expect($preview->status)->toBe(Preview::CLOSED)
        ->and($preview->status_message)->toContain('merged')
        ->and($preview->basic_password)->toBeNull()
        ->and(Site::query()->find($siteId))->toBeNull()
        ->and($this->domains->released)->toBe([$siteId])
        ->and($this->databases->deleted)->toBe([['id' => $databaseId, 'volume' => true]])
        ->and(Environment::query()->where('is_preview', true)->exists())->toBeFalse()
        ->and(array_values($this->sc->comments)[0]['body'])->toContain('removed');
});

/** Forks run on a dedicated preview server, as containers: the base site becomes a Docker site. */
function fork_ready(object $test): void
{
    $test->forkServer = sites_server($test->organization->id, ['name' => 'previews-1'], docker: true);
    Site::query()->whereKey($test->web->id)->update([
        'runtime' => 'docker', 'build_mode' => 'docker', 'framework' => 'docker', 'php_version' => null,
        'docker_image' => 'ghcr.io/acme/shop:1', 'container_port' => 8080, 'app_port' => 3100,
    ]);
    $test->settings->forceFill(['fork_server_id' => $test->forkServer->id, 'variables' => ['APP_NAME', 'DATABASE_URL']])->save();
    EnvironmentVersion::query()->where('site_id', $test->web->id)->firstOrFail()->forceFill(['variables' => [
        'APP_NAME' => 'Shop', 'APP_KEY' => 'base64:staging', 'STRIPE_SECRET' => 'sk_live_x', 'DATABASE_URL' => '${{ db.DATABASE_URL }}', 'MAIL_HOST' => 'smtp.internal',
    ]])->save();
}

it('never deploys a fork\'s pull request on its own, runs it isolated on the fork server and gives it no secrets', function () {
    fork_ready($this);
    open_pr($this, fork: true);

    $preview = previews_sole();
    expect($preview->status)->toBe(Preview::WAITING_APPROVAL)
        ->and($preview->environment_id)->toBeNull()
        ->and($this->databases->created)->toBe([])
        ->and(array_values($this->sc->comments)[0]['body'])->toContain('/falak preview');

    app(PreviewLifecycle::class)->approve($preview, $this->user->id, 'ui', $preview->head_sha);
    $preview->refresh();
    $environment = Environment::query()->findOrFail($preview->environment_id);
    $copy = Site::query()->with(['latestEnvironment', 'targets'])->findOrFail($preview->sites['web']);

    expect($environment->is_fork_preview)->toBeTrue()
        ->and($copy->targets->pluck('server_id')->all())->toBe([$this->forkServer->id])
        // Its own Linux user, never the base's shared one.
        ->and($copy->isolated)->toBeTrue()
        ->and($copy->unix_user)->not->toBe($this->web->unix_user)
        // The fork's branch lives in the fork: the copy keeps the base branch, the head commit is deployed.
        ->and($copy->branch)->toBe('main')
        // Only the allowlisted names; no literal secret, no other base value.
        ->and($copy->latestEnvironment->variables)->toBe(['APP_NAME' => 'Shop', 'DATABASE_URL' => '${{ db.DATABASE_URL }}'])
        // A fork never gets a plain copy of data.
        ->and($preview->databases['db']['strategy'])->toBe(PreviewSettings::EMPTY);

    // A new commit from the fork waits for a new approval; the old approval can't be replayed for it.
    preview_go_live($this, $preview);
    PullRequestUpdated::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(sha: 'e', fork: true));
    expect($preview->refresh()->status)->toBe(Preview::WAITING_APPROVAL)->and($this->deployments->deployed)->toHaveCount(1)
        ->and(fn () => app(PreviewLifecycle::class)->approve($preview, $this->user->id, 'ui', str_repeat('c', 40)))->toThrow(ValidationException::class);
});

it('refuses a fork without a dedicated fork server, on a server hosting other sites, on the edge, or running natively', function (string $case) {
    fork_ready($this);

    match ($case) {
        'no fork server' => $this->settings->forceFill(['fork_server_id' => null])->save(),
        'production server' => $this->settings->forceFill(['fork_server_id' => $this->server->id])->save(),
        'edge server' => $this->domains->configure($this->organization->id, 'prv.example.com', 'cred', $this->forkServer->id),
        'native runtime' => Site::query()->whereKey($this->web->id)->update(['runtime' => 'frankenphp', 'build_mode' => 'native', 'framework' => 'laravel', 'php_version' => '8.4']),
    };
    open_pr($this, fork: true);
    $preview = previews_sole();
    app(PreviewLifecycle::class)->approve($preview, $this->user->id, 'ui', $preview->head_sha);

    expect($preview->refresh()->status)->toBe(Preview::FAILED)
        ->and(Site::query()->count())->toBe(1)
        ->and($this->deployments->deployed)->toBe([]);
})->with(['no fork server', 'production server', 'edge server', 'native runtime']);

it('approves a fork through /falak preview only for members who connected that account id, for the head they saw', function () {
    fork_ready($this);
    open_pr($this, fork: true);
    $preview = previews_sole();
    $comment = fn (string $author, ?string $id, string $body = '/falak preview', ?string $at = null) => PullRequestCommented::dispatch(
        $this->organization->id, $this->connection->id, 'github', 'acme/shop', 7, '1', $author, $body, $id, new DateTimeImmutable($at ?? 'now'));

    // A stranger, a viewer who connected their account, and a login that matches a member's but not their id.
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->sc->accounts['github|2002'] = [$viewer->id];
    $this->sc->accounts['github|1001'] = [$this->user->id];
    $comment('mallory', '3003');
    $comment('vic', '2002');
    $comment('grace', null);
    expect($preview->refresh()->status)->toBe(Preview::WAITING_APPROVAL)
        // At most one refusal reply per pull request and hour.
        ->and(collect($this->sc->comments)->pluck('body')->filter(fn ($b) => str_contains($b, 'Only Falak project members'))->count())->toBe(1);

    // A member of another organization proves nothing.
    [$outsider] = memberOf(null, Role::Owner);
    $this->sc->accounts['github|4004'] = [$outsider->id];
    $comment('eve', '4004');
    expect($preview->refresh()->status)->toBe(Preview::WAITING_APPROVAL);

    // Other comments are ignored; a comment older than the head approves nothing.
    $comment('grace', '1001', 'looks good');
    $comment('grace', '1001', at: '-1 hour');
    expect($preview->refresh()->status)->toBe(Preview::WAITING_APPROVAL);

    $this->travel(2)->seconds();
    $comment('grace', '1001', "/falak preview\nplease");
    expect($preview->refresh()->status)->toBe(Preview::CREATING)->and($preview->approved_by)->toBe($this->user->id);
});

it('resolves only secrets available to previews, and none at all for a fork', function () {
    $create = app(CreateSecret::class);
    $create($this->organization->id, SecretScope::Project, $this->staging->project_id, ['name' => 'OPEN', 'value' => 'open-value', 'available_to_previews' => true], $this->user->id);
    $create($this->organization->id, SecretScope::Project, $this->staging->project_id, ['name' => 'CLOSED', 'value' => 'closed-value'], $this->user->id);
    $resolve = fn (Preview $p, string $ref) => app(VariableReferences::class)->resolveForSite($p->sites['web'], ['X' => $ref]);

    open_pr($this);
    $preview = previews_sole();
    expect($resolve($preview, '${{ secrets.OPEN }}')->variables['X'])->toBe('open-value')
        ->and($resolve($preview, '${{ secrets.CLOSED }}')->errors[0])->toContain('not available to preview environments');

    fork_ready($this);
    open_pr($this, number: 8, fork: true);
    $fork = Preview::query()->where('number', 8)->sole();
    app(PreviewLifecycle::class)->approve($fork, $this->user->id, 'ui', $fork->head_sha);
    expect($resolve($fork->refresh(), '${{ secrets.OPEN }}')->errors[0])->toContain('forks get no secrets');
});

it('copies only the allowlisted variables and references into same-repo previews, never literal secrets', function () {
    $this->settings->forceFill(['variables' => ['APP_KEY', 'APP_NAME']])->save();
    EnvironmentVersion::query()->where('site_id', $this->web->id)->firstOrFail()->forceFill(['variables' => [
        'APP_NAME' => 'Shop', 'APP_KEY' => 'base64:staging', 'STRIPE_SECRET' => 'sk_live_x', 'DATABASE_URL' => '${{ db.DATABASE_URL }}', 'MAIL_HOST' => 'smtp.internal',
    ]])->save();

    open_pr($this);
    $copy = Site::query()->with('latestEnvironment')->findOrFail(previews_sole()->sites['web']);

    expect($copy->latestEnvironment->variables)->toBe(['APP_NAME' => 'Shop', 'DATABASE_URL' => '${{ db.DATABASE_URL }}'])
        ->and($copy->isolated)->toBeTrue()
        ->and($copy->unix_user)->not->toBe($this->web->unix_user);
});

it('shares nothing with a fork, even services set to share', function () {
    fork_ready($this);
    projects_site($this->organization, 'api', ['URL' => 'https://api.staging'], $this->staging, [$this->server]);
    $this->settings->forceFill(['services' => ['api' => PreviewSettings::SHARE]])->save();

    open_pr($this, fork: true);
    $preview = previews_sole();
    app(PreviewLifecycle::class)->approve($preview, $this->user->id, 'ui', $preview->head_sha);

    $result = app(VariableReferences::class)->resolveForSite($preview->refresh()->sites['web'], ['API' => '${{ api.URL }}']);
    expect($result->errors[0])->toContain('unknown service')
        ->and(Environment::query()->findOrFail($preview->environment_id)->shared_services)->toBeNull();
});

it('shares the services set to share from the base environment and leaves out omitted ones', function () {
    $api = projects_site($this->organization, 'api', ['URL' => 'https://api.staging'], $this->staging, [$this->server]);
    projects_site($this->organization, 'worker', [], $this->staging, [$this->server]);
    $this->settings->forceFill(['services' => ['api' => PreviewSettings::SHARE, 'worker' => PreviewSettings::OMIT]])->save();

    open_pr($this);
    $preview = previews_sole();

    expect(array_keys($preview->sites))->toBe(['web'])
        ->and(app(VariableReferences::class)->resolveForSite($preview->sites['web'], ['API' => '${{ api.URL }}'])->variables['API'])->toBe('https://api.staging');
});

it('restores the newest backup of the chosen environment into the preview database', function () {
    $this->settings->forceFill(['databases' => ['db' => ['strategy' => PreviewSettings::CLONE_BACKUP]]])->save();
    open_pr($this);
    $preview = previews_sole();
    $databaseId = $preview->databases['db']['database_id'];

    DatabaseCreated::dispatch($databaseId, $this->organization->id, $this->server->id, 'db', 'postgresql', null);
    expect($this->databases->restores)->toHaveCount(1)
        ->and($this->databases->restores[0])->toMatchArray(['source' => $this->stagingDb->id, 'target' => $databaseId])
        ->and($this->deployments->deployed)->toBe([]);

    RestoreFinished::dispatch($this->databases->restores[0]['id'], $this->organization->id, 'b', $this->server->id, 'db', true, null);
    expect($this->deployments->deployed)->toHaveCount(1)->and($this->databases->scripts)->toBe([]);
});

it('sanitizes production data before the preview starts, and fails closed when the script fails', function (bool $scriptSucceeds) {
    [$productionDb] = projects_database($this->organization, 'db', $this->production);
    $this->settings->forceFill(['databases' => ['db' => ['strategy' => PreviewSettings::CLONE_SANITIZE, 'sanitize_kind' => 'sql', 'sanitize_script' => 'UPDATE users SET email = id;']]])->save();
    open_pr($this);
    $preview = previews_sole();
    $databaseId = $preview->databases['db']['database_id'];

    DatabaseCreated::dispatch($databaseId, $this->organization->id, $this->server->id, 'db', 'postgresql', null);
    expect($this->databases->restores[0]['source'])->toBe($productionDb->id);
    RestoreFinished::dispatch($this->databases->restores[0]['id'], $this->organization->id, 'b', $this->server->id, 'db', true, null);

    $script = $this->databases->scripts[0];
    expect($script)->toMatchArray(['database' => $databaseId, 'kind' => 'sql', 'script' => 'UPDATE users SET email = id;'])
        ->and($this->deployments->deployed)->toBe([]);

    $scriptSucceeds
        ? CommandFinished::dispatch('cmd', $this->organization->id, $this->server->id, 'system.exec', $script['key'], 0, ['exit_code' => 0])
        : CommandFailed::dispatch('cmd', $this->organization->id, $this->server->id, 'system.exec', $script['key'], 'failed', 'syntax error', 1);

    $preview->refresh();

    if ($scriptSucceeds) {
        expect($this->deployments->deployed)->toHaveCount(1);

        return;
    }

    expect($preview->status)->toBe(Preview::FAILED)
        ->and($preview->status_message)->toContain('sanitize script')
        ->and($this->deployments->deployed)->toBe([])
        // The unsanitized copy of production is deleted at once.
        ->and($this->databases->deleted)->toBe([['id' => $databaseId, 'volume' => true]]);
})->with(['succeeds' => true, 'fails' => false]);

it('fails clearly when the clone has no backup', function () {
    $this->databases->noBackup = true;
    $this->settings->forceFill(['databases' => ['db' => ['strategy' => PreviewSettings::CLONE_BACKUP]]])->save();
    open_pr($this);
    $preview = previews_sole();

    DatabaseCreated::dispatch($preview->databases['db']['database_id'], $this->organization->id, $this->server->id, 'db', 'postgresql', null);

    expect($preview->refresh()->status)->toBe(Preview::FAILED)->and($preview->status_message)->toContain('no successful backup');
});

it('queues pull requests past the project\'s limit and starts them when one closes', function () {
    $this->settings->forceFill(['max_concurrent' => 1])->save();
    open_pr($this, number: 1);
    open_pr($this, number: 2);

    $second = Preview::query()->where('number', 2)->sole();
    expect($second->status)->toBe(Preview::QUEUED)->and($second->environment_id)->toBeNull()
        ->and(collect($this->sc->comments)->firstWhere('number', 2)['body'])->toContain('limit');

    PullRequestClosed::dispatch($this->organization->id, $this->connection->id, 'github', previews_pr(number: 1), false);

    expect($second->refresh()->status)->toBe(Preview::CREATING)->and($second->environment_id)->not->toBeNull();
});

it('deletes previews idle past the TTL', function () {
    $this->settings->forceFill(['idle_ttl_hours' => 72])->save();
    open_pr($this);
    $preview = preview_go_live($this, previews_sole());

    expect(app(PreviewLifecycle::class)->cleanupIdle())->toBe(0);

    Carbon::setTestNow(now()->addHours(73));
    expect(app(PreviewLifecycle::class)->cleanupIdle())->toBe(1)
        ->and($preview->refresh()->status)->toBe(Preview::CLOSED)
        ->and($preview->status_message)->toContain('Idle');
    Carbon::setTestNow();
});

it('marks the preview failed when a deployment fails', function () {
    open_pr($this);
    $preview = previews_sole();
    DatabaseCreated::dispatch($preview->databases['db']['database_id'], $this->organization->id, $this->server->id, 'db', 'postgresql', null);

    DeploymentFailed::dispatch('dep', $this->organization->id, $preview->sites['web'], 'web', 1, 'manual', 'build', 'npm ci failed', null, false);

    expect($preview->refresh()->status)->toBe(Preview::FAILED)->and($preview->status_message)->toContain('npm ci failed')
        ->and(collect($this->sc->statuses)->last()['state'])->toBe('failure');
});

it('ignores repositories no preview-enabled base environment deploys, and other organizations\' events', function () {
    open_pr($this, repository: 'acme/blog');
    [, $other] = memberOf(null, Role::Owner);
    PullRequestOpened::dispatch($other->id, $this->connection->id, 'github', previews_pr());

    $this->settings->forceFill(['enabled' => false])->save();
    open_pr($this);

    expect(Preview::query()->count())->toBe(0);
});

it('serves public previews without basic auth when the project chose so', function () {
    $this->settings->forceFill(['access' => 'public'])->save();
    open_pr($this);

    expect($this->domains->protected)->toBe([])->and(previews_sole()->basic_username)->toBeNull();
});
