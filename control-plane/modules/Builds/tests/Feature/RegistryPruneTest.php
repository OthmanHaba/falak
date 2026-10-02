<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kiln\Builds\Application\RegistryPruner;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Deployments\Contracts\RetainedImages;
use Kiln\Identity\Contracts\Role;
use Kiln\Sites\Contracts\SiteFactory;

/*
 * The built-in registry cleanup (kiln:registry-prune, daily): images of builds whose artifact was pruned go, unless a
 * release may still run them; deleted sites' images go after the grace period. Against a fake registry API.
 */

require_once __DIR__.'/../../../Sites/tests/Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Admin);
    sites_fake_agents();
    config([
        'builds.registry.url' => 'registry.test', 'builds.registry.namespace' => 'kiln',
        'builds.registry.username' => 'kiln', 'builds.registry.password' => 'secret',
        'builds.registry.deleted_site_grace_days' => 7,
    ]);
    $server = sites_server($this->organization->id, ['name' => 'app-1'], docker: true);
    $this->site = app(SiteFactory::class)->create($this->organization->id, $this->user->id, [
        'name' => 'shop', 'framework' => 'docker', 'runtime' => 'docker', 'docker_image' => 'nginx:1.29', 'container_port' => 80, 'server_ids' => [$server->id],
    ])->site;

    $this->retained = [];
    app()->instance(RetainedImages::class, new class($this) implements RetainedImages
    {
        public function __construct(private object $test) {}

        public function images(): array
        {
            return $this->test->retained;
        }
    });

    // Fake registry: repository => tag => digest.
    $this->repos = [];
    $this->deleted = [];
    $this->deleteStatus = 202;
    $this->headStatus = []; // tag => HTTP status of its HEAD
    $this->onRequest = null;
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($this->onRequest) {
            ($this->onRequest)($request->method(), $path);
        }
        if ($request->method() === 'GET' && $path === '/v2/_catalog') {
            return Http::response(['repositories' => array_keys($this->repos)]);
        }
        if (preg_match('#^/v2/(.+)/tags/list$#', $path, $m)) {
            return isset($this->repos[$m[1]]) ? Http::response(['name' => $m[1], 'tags' => array_keys($this->repos[$m[1]])]) : Http::response([], 404);
        }
        if (preg_match('#^/v2/(.+)/manifests/(.+)$#', $path, $m)) {
            if ($request->method() === 'HEAD') {
                if (isset($this->headStatus[$m[2]])) {
                    return Http::response('', $this->headStatus[$m[2]]);
                }
                $digest = $this->repos[$m[1]][$m[2]] ?? null;

                return $digest ? Http::response('', 200, ['Docker-Content-Digest' => $digest]) : Http::response('', 404);
            }
            if ($request->method() === 'DELETE') {
                if ($this->deleteStatus !== 202) {
                    return Http::response('', $this->deleteStatus);
                }
                $this->deleted[] = "{$m[1]}@{$m[2]}";

                return Http::response('', 202);
            }
        }

        return Http::response('', 500);
    });
});

function registry_build(object $test, ?string $siteId, array $attributes = []): Build
{
    $id = strtolower((string) Str::ulid($attributes['created_at'] ?? now()));

    return Build::query()->create([
        'id' => $id, 'organization_id' => $test->organization->id, 'site_id' => $siteId ?? strtolower((string) Str::ulid()), 'site_slug' => 'shop',
        'mode' => 'docker', 'status' => BuildStatus::Succeeded, 'timeout_s' => 1800, 'cache_key' => 'k', 'created_at' => now()->subDays(3), ...$attributes,
    ]);
}

function registry_digest(string $seed): string
{
    return 'sha256:'.hash('sha256', $seed);
}

it('deletes images of pruned builds and keeps kept, recent, running and released ones', function () {
    $kept = registry_build($this, $this->site->id);
    $pruned = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    $released = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    $recent = registry_build($this, $this->site->id, ['artifact_pruned_at' => now(), 'created_at' => now()->subHours(2)]);
    $running = registry_build($this, $this->site->id, ['status' => BuildStatus::Running, 'created_at' => now()->subDays(2)]);
    $this->repos = [
        'kiln/shop' => [$kept->id => registry_digest('a'), $pruned->id => registry_digest('b'), $recent->id => registry_digest('c'), $running->id => registry_digest('d'), 'latest' => registry_digest('e')],
        'kiln/shop/api' => [$released->id => registry_digest('f')],
        'other/thing' => ['01j9zq4n8v2m6r0t3w5y7b9d1f' => registry_digest('g')],
    ];
    // A rollback release still runs the compose service image, pinned by digest.
    $this->retained = ['registry.test/kiln/shop/api:'.$released->id.'@'.registry_digest('f')];

    $result = app(RegistryPruner::class)->prune();

    expect($this->deleted)->toBe(['kiln/shop@'.registry_digest('b')])
        ->and($result['kept'])->toBe(5)
        ->and($result['skipped'])->toBeNull();
});

it('never deletes a digest a kept tag also points at', function () {
    $kept = registry_build($this, $this->site->id);
    $pruned = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    // A reused build: same image pushed under two tags.
    $this->repos = ['kiln/shop' => [$kept->id => registry_digest('same'), $pruned->id => registry_digest('same')]];

    app(RegistryPruner::class)->prune();

    expect($this->deleted)->toBe([]);
});

it('deletes nothing on a dry run', function () {
    $pruned = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    $this->repos = ['kiln/shop' => [$pruned->id => registry_digest('b')]];

    $result = app(RegistryPruner::class)->prune(dryRun: true);

    expect($this->deleted)->toBe([])
        ->and($result['deleted'])->toHaveCount(1);
    $this->artisan('kiln:registry-prune', ['--dry-run' => true])->expectsOutputToContain('would delete kiln/shop@')->assertSuccessful();
    expect($this->deleted)->toBe([]);
});

it('deletes images of deleted sites after the grace period only', function () {
    $old = registry_build($this, null, ['created_at' => now()->subDays(10)]);
    $young = registry_build($this, null, ['created_at' => now()->subDays(3)]);
    $this->repos = ['kiln/gone' => [$old->id => registry_digest('old'), $young->id => registry_digest('young')]];

    app(RegistryPruner::class)->prune();

    expect($this->deleted)->toBe(['kiln/gone@'.registry_digest('old')]);
});

it('stops when the registry does not allow deletes, and does nothing without credentials', function () {
    $pruned = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    $this->repos = ['kiln/shop' => [$pruned->id => registry_digest('b')]];
    $this->deleteStatus = 405;

    expect(app(RegistryPruner::class)->prune()['skipped'])->toContain('does not allow deletes');

    config(['builds.registry.password' => '']);
    expect(app(RegistryPruner::class)->prune()['skipped'])->toContain('no credentials');
});

it('leaves a repository alone when a digest can’t be read', function () {
    $kept = registry_build($this, $this->site->id);
    $pruned = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    $other = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    // The kept tag's HEAD fails; it shares the pruned tag's digest, so deleting that would take the kept image too.
    $this->repos = [
        'kiln/shop' => [$kept->id => registry_digest('same'), $pruned->id => registry_digest('same')],
        'kiln/shop/api' => [$other->id => registry_digest('x')],
    ];
    $this->headStatus = [$kept->id => 500];

    $result = app(RegistryPruner::class)->prune();

    expect($this->deleted)->toBe(['kiln/shop/api@'.registry_digest('x')])
        ->and($result['kept'])->toBe(2);
});

it('re-reads digests right before deleting: tags pushed or moved since the scan keep theirs', function () {
    $pruned = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    $moved = registry_build($this, $this->site->id, ['artifact_pruned_at' => now()]);
    $kept = registry_build($this, $this->site->id);
    $this->repos = ['kiln/shop' => [$pruned->id => registry_digest('reused'), $moved->id => registry_digest('moved')]];

    // After the scan (first tag list) a kept build is pushed with the pruned tag's image, and the other tag moves.
    $lists = 0;
    $this->onRequest = function (string $method, string $path) use (&$lists, $kept, $moved) {
        if ($path === '/v2/kiln/shop/tags/list' && ++$lists === 2) {
            $this->repos['kiln/shop'][$kept->id] = registry_digest('reused');
            $this->repos['kiln/shop'][$moved->id] = registry_digest('elsewhere');
        }
    };

    app(RegistryPruner::class)->prune();

    expect($this->deleted)->toBe([]);
});

it('tells kiln-ctl whether the registry can stop for garbage collection', function () {
    registry_build($this, $this->site->id, ['mode' => 'native', 'status' => BuildStatus::Running]);
    registry_build($this, $this->site->id, ['status' => BuildStatus::Failed]);
    $this->artisan('kiln:registry-idle')->expectsOutputToContain('No image build')->assertExitCode(0);

    registry_build($this, $this->site->id, ['status' => BuildStatus::Queued]);
    $this->artisan('kiln:registry-idle')->expectsOutputToContain('1 image build(s) queued or running.')->assertExitCode(1);
});
