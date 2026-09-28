<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\AuditEntry;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Sites\Application\Actions\SaveEnvironment;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Events\SiteEnvironmentChanged;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = actingAsMember(Role::Developer);
    sites_fake_agents();
    sites_fake_source_control();
    $server = sites_server($this->organization->id);
    $this->post('/sites', sites_input([$server->id]));
    $this->site = Site::query()->firstOrFail();
});

it('stores environment values encrypted and never renders them in the page', function () {
    $raw = DB::table('sites_environment_versions')->value('variables');
    expect($raw)->not->toContain('APP_KEY');

    $this->getJson("/sites/{$this->site->id}/environment")->assertOk()
        ->assertJsonPath('data.current.version', 1)
        ->assertJsonPath('data.current.keys', ['APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'LOG_CHANNEL'])
        ->assertJsonMissingPath('data.current.variables');

    expect($this->getJson("/sites/{$this->site->id}/environment")->getContent())->not->toContain('base64:');

    // The legacy page opens the canvas panel's Variables tab.
    $this->get("/sites/{$this->site->id}/environment")->assertRedirect(app(ProjectDirectory::class)->serviceUrl('site', $this->site->id, 'variables'));
});

it('applies staged row edits as one version and shows reference-only values', function () {
    $this->patchJson("/sites/{$this->site->id}/environment", [
        'set' => ['APP_NAME' => 'Renamed', 'DB_URL' => '${{ missing-db.DATABASE_URL }}'],
        'unset' => ['LOG_CHANNEL'],
        'exposed' => ['APP_NAME' => true],
        'base_version' => 1,
    ])->assertOk()->assertJsonPath('data.version', 2);

    $env = app(SiteDirectory::class)->environment($this->site->id);
    expect($env->variables)->toHaveKey('APP_KEY')->not->toHaveKey('LOG_CHANNEL')
        ->and($env->variables['APP_NAME'])->toBe('Renamed')
        ->and($env->exposedToDeployScript)->toBe(['APP_NAME']);

    $state = $this->getJson("/sites/{$this->site->id}/environment")->assertOk();
    expect($state->json('data.current.references'))->toBe(['DB_URL' => '${{ missing-db.DATABASE_URL }}'])
        ->and($state->json('data.current.referencing'))->toBe(['DB_URL'])
        ->and($state->json('data.current.reference_errors.0'))->toContain('missing-db')
        ->and($state->getContent())->not->toContain('Renamed');

    // Stale base version and invalid names are rejected.
    $this->patchJson("/sites/{$this->site->id}/environment", ['set' => ['A' => '1'], 'unset' => [], 'exposed' => [], 'base_version' => 1])->assertUnprocessable();
    $this->patchJson("/sites/{$this->site->id}/environment", ['set' => ['1BAD' => '1'], 'unset' => [], 'exposed' => []])->assertUnprocessable();

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->patchJson("/sites/{$this->site->id}/environment", ['set' => ['A' => '1'], 'unset' => [], 'exposed' => []])->assertForbidden();
});

it('reveals values with an audit entry, only with sites.env.view', function () {
    $response = $this->postJson("/sites/{$this->site->id}/environment/reveal")->assertOk();

    expect($response->json('data.content'))->toContain("APP_NAME=Shop\n")->toContain('APP_KEY=base64:')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(AuditEntry::query()->where('action', 'site.environment_revealed')->exists())->toBeTrue();

    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->postJson("/sites/{$this->site->id}/environment/reveal")->assertForbidden();
});

it('saves a new version with changed keys, exposure and an event', function () {
    Event::fake([SiteEnvironmentChanged::class]);

    $content = <<<'ENV'
        # production
        APP_NAME="My Shop"
        APP_ENV=production
        export DB_PASSWORD='p@ss $word'
        MAIL_FROM=hello@example.com # inline comment
        MULTI="line1\nline2"
        ENV;

    $this->put("/sites/{$this->site->id}/environment", ['content' => $content, 'exposed' => ['APP_ENV', 'MISSING'], 'base_version' => 1])->assertSessionHasNoErrors();

    $env = app(SiteDirectory::class)->environment($this->site->id);
    expect($env->version)->toBe(2)
        ->and($env->variables)->toBe(['APP_NAME' => 'My Shop', 'APP_ENV' => 'production', 'DB_PASSWORD' => 'p@ss $word', 'MAIL_FROM' => 'hello@example.com', 'MULTI' => "line1\nline2"])
        ->and($env->exposedToDeployScript)->toBe(['APP_ENV'])
        ->and($env->deployScriptVariables())->toBe(['APP_ENV' => 'production'])
        ->and(EnvironmentVersion::query()->where('version', 2)->first()->changed_keys)->toBe(['APP_DEBUG', 'APP_KEY', 'APP_NAME', 'APP_URL', 'DB_PASSWORD', 'LOG_CHANNEL', 'MAIL_FROM', 'MULTI']);

    // The rendered .env round-trips through the parser.
    $this->put("/sites/{$this->site->id}/environment", ['content' => $env->toDotenv(), 'exposed' => ['APP_ENV'], 'base_version' => 2])->assertSessionHasNoErrors();
    expect(EnvironmentVersion::query()->count())->toBe(2);

    Event::assertDispatchedTimes(SiteEnvironmentChanged::class, 1);
    Event::assertDispatched(SiteEnvironmentChanged::class, fn ($e) => $e->version === 2 && in_array('DB_PASSWORD', $e->changedKeys, true));

    $audit = AuditEntry::query()->where('action', 'site.environment_updated')->firstOrFail();
    expect(json_encode($audit->context))->not->toContain('p@ss');

    expect(app(SiteDirectory::class)->environment($this->site->id, 1)->variables)->toHaveKey('APP_KEY');
});

it('rejects invalid dotenv content and stale edits', function () {
    $this->put("/sites/{$this->site->id}/environment", ['content' => "GOOD=1\nbad line", 'exposed' => []])->assertSessionHasErrors('content');
    $this->put("/sites/{$this->site->id}/environment", ['content' => '1BAD=x', 'exposed' => []])->assertSessionHasErrors('content');
    $this->put("/sites/{$this->site->id}/environment", ['content' => 'A="unterminated', 'exposed' => []])->assertSessionHasErrors('content');

    $this->put("/sites/{$this->site->id}/environment", ['content' => 'A=1', 'exposed' => [], 'base_version' => 1])->assertSessionHasNoErrors();
    $this->put("/sites/{$this->site->id}/environment", ['content' => 'A=2', 'exposed' => [], 'base_version' => 1])->assertSessionHasErrors('content');
});

it('restores an older version as a new version', function () {
    $this->put("/sites/{$this->site->id}/environment", ['content' => 'A=1', 'exposed' => []]);
    $this->post("/sites/{$this->site->id}/environment/versions/1/restore")->assertSessionHasNoErrors();

    $env = app(SiteDirectory::class)->environment($this->site->id);
    expect($env->version)->toBe(3)->and($env->variables)->toHaveKey('APP_KEY')
        ->and(AuditEntry::query()->where('action', 'site.environment_restored')->exists())->toBeTrue();
});

it('lets viewers see keys but not edit', function () {
    [$viewer] = memberOf($this->organization, Role::Viewer);
    $this->actingAs($viewer)->getJson("/sites/{$this->site->id}/environment")->assertOk()->assertJsonPath('data.can.update', false);
    $this->actingAs($viewer)->put("/sites/{$this->site->id}/environment", ['content' => 'A=1', 'exposed' => []])->assertForbidden();
});

it('creates Laravel sites logging to files and migrates the old stderr default', function () {
    expect(EnvironmentVersion::query()->where('site_id', $this->site->id)->firstOrFail()->variables['LOG_CHANNEL'])->toBe('daily');

    $current = EnvironmentVersion::query()->where('site_id', $this->site->id)->firstOrFail();
    app(SaveEnvironment::class)($this->site, ['LOG_CHANNEL' => 'stderr', 'A' => 'b'], ['A'], null);
    $custom = Site::query()->create(array_merge($this->site->only(['organization_id', 'runtime', 'build_mode', 'framework', 'php_version', 'unix_user', 'deploy_script', 'laravel', 'shared_paths']), ['name' => 'Custom', 'slug' => 'custom']));
    app(SaveEnvironment::class)($custom, ['LOG_CHANNEL' => 'papertrail'], [], null);

    (require base_path('modules/Sites/database/migrations/2026_10_03_600001_switch_laravel_sites_to_file_logs.php'))->up();

    $latest = EnvironmentVersion::query()->where('site_id', $this->site->id)->orderByDesc('version')->firstOrFail();
    expect($latest->version)->toBe($current->version + 2)
        ->and($latest->variables)->toBe(['LOG_CHANNEL' => 'daily', 'A' => 'b'])
        ->and($latest->exposed)->toBe(['A'])
        ->and($latest->changed_keys)->toBe(['LOG_CHANNEL'])
        ->and(EnvironmentVersion::query()->where('site_id', $custom->id)->count())->toBe(1);
});
