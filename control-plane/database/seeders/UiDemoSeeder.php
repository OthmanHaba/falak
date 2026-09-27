<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Kiln\Databases\Application\EngineInventory;
use Kiln\Databases\Domain\Enums\ResourceStatus;
use Kiln\Databases\Domain\Enums\StorageDriver;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\Grant;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Deployments\Domain\Models\DeploymentTarget;
use Kiln\Deployments\Domain\Models\OutputLine;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Identity\Application\Actions\CreateOrganization;
use Kiln\Identity\Application\Actions\RegisterUser;
use Kiln\Identity\Domain\Models\User;
use Kiln\Projects\Application\Actions\CreateEnvironment;
use Kiln\Projects\Application\Actions\CreateProject;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Sites\Contracts\BuildMode;
use Kiln\Sites\Contracts\Data\LaravelSettings;
use Kiln\Sites\Contracts\Framework;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetRole;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\EnvironmentVersion;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;

/**
 * Local UI demo data (`php artisan db:seed --class=UiDemoSeeder`): an admin, an organization, a few servers and
 * sites so the redesigned pages have something to render. Same credentials as the sim: admin@kiln.test / kiln-demo-2026.
 * Written directly against module models like DatabaseSeeder — never run in production.
 */
class UiDemoSeeder extends Seeder
{
    public function run(RegisterUser $register, CreateOrganization $createOrganization): void
    {
        if (User::query()->where('email', 'admin@kiln.test')->exists()) {
            return;
        }

        $admin = $register('Ada Admin', 'admin@kiln.test', 'kiln-demo-2026');
        $admin->markEmailAsVerified();
        $organization = $createOrganization($admin, 'Acme Studio');
        $admin->forceFill(['current_organization_id' => $organization->id])->save();

        $servers = collect([
            ['app-1', ServerType::App, ServerStatus::Active],
            ['app-2', ServerType::App, ServerStatus::Active],
            ['db-1', ServerType::Database, ServerStatus::Active],
            ['worker-1', ServerType::Worker, ServerStatus::Provisioning],
            ['edge-1', ServerType::LoadBalancer, ServerStatus::Error],
        ])->map(fn (array $spec) => Server::factory()->type($spec[1])->status($spec[2])->create([
            'organization_id' => $organization->id,
            'name' => $spec[0],
            ...($spec[0] === 'db-1' ? ['stack' => ['database' => 'postgresql'], 'private_ipv4' => '10.0.0.12'] : []),
        ]));

        $sites = [
            ['Storefront', 'storefront', SiteRuntime::FrankenPhp, Framework::Laravel, 'acme/storefront'],
            ['Marketing', 'marketing', SiteRuntime::Node, Framework::Next, 'acme/marketing-site'],
            ['Docs', 'docs', SiteRuntime::Static, Framework::Static, 'acme/docs'],
            ['Blog', 'blog', SiteRuntime::PhpFpm, Framework::WordPress, null],
        ];

        $created = [];

        foreach ($sites as $index => [$name, $slug, $runtime, $framework, $repository]) {
            $site = $created[$slug] = Site::query()->create([
                'organization_id' => $organization->id,
                'name' => $name,
                'slug' => $slug,
                'runtime' => $runtime,
                'build_mode' => BuildMode::Native,
                'framework' => $framework,
                'php_version' => in_array($runtime, [SiteRuntime::FrankenPhp, SiteRuntime::PhpFpm], true) ? '8.4' : null,
                'web_directory' => 'public',
                'unix_user' => $slug,
                'isolated' => true,
                'repository' => $repository,
                'branch' => $repository ? 'main' : null,
                'deploy_script' => '$KILN_FETCH',
                'laravel' => new LaravelSettings,
                'shared_paths' => [],
            ]);

            foreach ($index === 0 ? [$servers[0], $servers[1]] : [$servers[$index % 2]] as $position => $server) {
                SiteTarget::query()->create([
                    'site_id' => $site->id,
                    'server_id' => $server->id,
                    'role' => $position === 0 ? TargetRole::Leader : TargetRole::Member,
                    'status' => TargetStatus::Ready,
                ]);
            }
        }

        $this->database($organization->id, $servers[2]);
        $this->variables($created['storefront'], ['APP_ENV' => 'production', 'DATABASE_URL' => '${{ storefront_db.DATABASE_URL }}', 'DB_HOST' => '${{ storefront_db.DB_HOST }}']);
        $this->deployments($organization->id, $created, [$servers[0], $servers[1]]);

        // Place the sites into the organization's Default project, and add a second project with staging so the
        // project/environment switchers have something to switch between.
        Artisan::call('projects:backfill', ['--organization' => $organization->id]);
        $platform = app(CreateProject::class)($organization->id, $admin->id, ['name' => 'Platform']);
        app(CreateEnvironment::class)($platform, 'staging', $admin->id);

        // Organization settings: git connections, clouds, buckets, builders, alerting, recipes.
        $this->callWith(SettingsDemoSeeder::class, ['organizationId' => $organization->id, 'userId' => $admin->id]);
    }

    /**
     * A PostgreSQL engine on db-1 with the Storefront database, a user, a nightly schedule and backup history.
     */
    private function database(string $organizationId, Server $server): Database
    {
        $engine = app(EngineInventory::class)->sync($server->id) ?? throw new \RuntimeException('db-1 has no engine');
        $database = $engine->databases()->create(['organization_id' => $organizationId, 'server_id' => $server->id, 'name' => 'storefront_db', 'status' => ResourceStatus::Active]);
        $user = $engine->users()->create([
            'organization_id' => $organizationId, 'server_id' => $server->id, 'username' => 'storefront', 'password' => 'demo-password-not-real', 'host' => '%', 'status' => ResourceStatus::Active,
        ]);
        Grant::query()->create(['user_id' => $user->id, 'database_id' => $database->id, 'privileges' => ['ALL PRIVILEGES']]);

        $storage = StorageProvider::query()->create([
            'organization_id' => $organizationId, 'name' => 'Backups', 'driver' => StorageDriver::R2, 'endpoint' => 'https://example.r2.cloudflarestorage.com',
            'region' => 'auto', 'bucket' => 'acme-backups', 'path_style' => false, 'access_key_id' => 'demo-access-key-id', 'secret_access_key' => 'demo-secret', 'verified_at' => now(),
        ]);
        $schedule = BackupSchedule::query()->create([
            'organization_id' => $organizationId, 'database_server_id' => $engine->id, 'storage_provider_id' => $storage->id, 'name' => 'Nightly',
            'cron' => '0 3 * * *', 'retention_count' => 14, 'compression' => 'gzip', 'enabled' => true, 'last_run_at' => now()->subDay()->setTime(3, 0), 'next_run_at' => now()->addDay()->setTime(3, 0),
        ]);
        $schedule->databases()->attach($database->id);

        foreach ([[1, 'succeeded', 48_211_004], [2, 'succeeded', 47_902_311], [3, 'failed', null]] as [$daysAgo, $status, $size]) {
            Backup::query()->create([
                'organization_id' => $organizationId, 'schedule_id' => $schedule->id, 'database_id' => $database->id, 'database_server_id' => $engine->id,
                'server_id' => $server->id, 'server_name' => $server->name, 'database_name' => $database->name, 'engine' => 'postgresql', 'storage_provider_id' => $storage->id,
                'object_key' => "storefront_db/{$daysAgo}.sql.gz", 'compression' => 'gzip', 'trigger' => 'scheduled', 'status' => $status, 'size_bytes' => $size,
                'duration_ms' => $size ? 8200 + $daysAgo * 100 : null, 'error' => $size ? null : 'pg_dump: connection timed out', 'started_at' => now()->subDays($daysAgo),
                'finished_at' => now()->subDays($daysAgo)->addSeconds(9), 'created_at' => now()->subDays($daysAgo),
            ]);
        }

        return $database;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function variables(Site $site, array $variables): void
    {
        EnvironmentVersion::query()->create(['site_id' => $site->id, 'version' => 1, 'variables' => $variables, 'exposed' => [], 'changed_keys' => array_keys($variables), 'created_at' => now()]);
    }

    /**
     * Deployment history with releases, per-server phase steps and output: Storefront has a live deployment in
     * progress plus one queued, Marketing is healthy, Docs failed, Blog was never deployed.
     *
     * @param  array<string, Site>  $sites
     * @param  list<Server>  $servers
     */
    private function deployments(string $organizationId, array $sites, array $servers): void
    {
        $messages = ['Fix checkout rounding', 'Add wishlist sharing', 'Upgrade to Laravel 12', 'Speed up product search'];

        foreach ([[$sites['storefront'], ['failed', 'succeeded', 'succeeded', 'deploying', 'queued'], $servers], [$sites['marketing'], ['succeeded'], [$servers[1]]], [$sites['docs'], ['failed'], [$servers[1]]]] as [$site, $statuses, $targets]) {
            $active = null;

            foreach ($statuses as $index => $status) {
                $number = $index + 1;
                $started = now()->subHours(count($statuses) - $index)->subMinutes(7);
                $finished = in_array($status, ['succeeded', 'failed'], true) ? $started->copy()->addSeconds(74 + $index * 9) : null;
                $commit = substr(hash('sha1', $site->slug.$number), 0, 40);

                $deployment = Deployment::query()->create([
                    'organization_id' => $organizationId, 'site_id' => $site->id, 'site_slug' => $site->slug, 'number' => $number,
                    'trigger' => $index === 0 ? 'manual' : 'push', 'status' => $status, 'phase' => $status === 'deploying' ? 'migrate' : null, 'strategy' => 'zero-downtime',
                    'branch' => 'main', 'commit' => $commit, 'commit_message' => $messages[$index % count($messages)], 'commit_author' => $index % 2 ? 'Grace Hopper' : 'Ada Admin',
                    'error' => $status === 'failed' ? 'Health check failed: GET /up returned 500 on app-1.' : null,
                    'started_at' => $status === 'queued' ? null : $started, 'finished_at' => $finished, 'created_at' => $started, 'updated_at' => $finished ?? $started,
                ]);

                if ($status === 'queued') {
                    continue;
                }

                if ($status === 'succeeded') {
                    $release = Release::query()->forceCreate([
                        'id' => strtolower((string) Str::ulid()),
                        'organization_id' => $organizationId, 'site_id' => $site->id, 'deployment_id' => $deployment->id, 'commit' => $commit, 'branch' => 'main',
                        'commit_message' => $deployment->commit_message, 'commit_author' => $deployment->commit_author, 'status' => 'inactive', 'activated_at' => $finished,
                    ]);
                    $deployment->forceFill(['release_id' => $release->id])->save();
                    $active = $release;
                }

                $this->steps($deployment, $targets, $status, $started);
            }

            $active?->forceFill(['status' => 'active'])->save();
        }
    }

    /**
     * @param  list<Server>  $servers
     */
    private function steps(Deployment $deployment, array $servers, string $status, Carbon $at): void
    {
        $phases = ['fetch', 'prepare', 'migrate', 'activate', 'restart', 'healthcheck'];
        $position = 0;
        $clock = $at->copy();

        $build = DeploymentStep::query()->create([
            'deployment_id' => $deployment->id, 'key' => 'build', 'kind' => 'build', 'phase' => 'build', 'position' => $position++, 'depends_on' => [],
            'status' => 'succeeded', 'started_at' => $clock->copy(), 'finished_at' => $clock->addSeconds(38)->copy(),
        ]);
        foreach (["\e[1;34m==>\e[0m Installing dependencies", 'composer install --no-dev --optimize-autoloader', 'Generating optimized autoload files', "\e[1;34m==>\e[0m Building assets", 'vite v6.0.7 building for production...', "\e[32m✓\e[0m 1482 modules transformed.", "\e[32m✓ built in 6.21s\e[0m", 'Uploading artifact (42.1 MB)'] as $i => $line) {
            OutputLine::query()->create(['deployment_id' => $deployment->id, 'step_id' => $build->id, 'phase' => 'build', 'stream' => 'stdout', 'data' => $line."\n", 'at' => $at->copy()->addSeconds($i * 4)]);
        }

        foreach ($servers as $serverIndex => $server) {
            $target = DeploymentTarget::query()->create([
                'deployment_id' => $deployment->id, 'server_id' => $server->id, 'server_name' => $server->name, 'role' => $serverIndex === 0 ? 'leader' : 'member',
                'position' => $serverIndex, 'status' => match ($status) {
                    'deploying' => 'deploying', 'failed' => 'failed', default => 'succeeded'
                }, 'activated' => $status === 'succeeded',
            ]);
            $time = $clock->copy();

            foreach ($phases as $phaseIndex => $phase) {
                if ($phase === 'migrate' && $serverIndex > 0) {
                    continue;
                }
                $stepStatus = match (true) {
                    $status === 'deploying' => $phaseIndex < 2 ? 'succeeded' : ($phaseIndex === 2 || ($phaseIndex === 3 && $serverIndex > 0) ? 'running' : 'pending'),
                    $status === 'failed' => $phase === 'healthcheck' && $serverIndex === 0 ? 'failed' : ($phase === 'healthcheck' ? 'skipped' : 'succeeded'),
                    default => 'succeeded',
                };
                $started = in_array($stepStatus, ['succeeded', 'failed', 'running'], true) ? $time->copy() : null;
                $done = in_array($stepStatus, ['succeeded', 'failed'], true) ? $time->addSeconds(3 + $phaseIndex * 2)->copy() : null;
                $step = DeploymentStep::query()->create([
                    'deployment_id' => $deployment->id, 'target_id' => $target->id, 'server_id' => $server->id, 'key' => "{$phase}-{$server->name}", 'kind' => $phase === 'migrate' ? 'hook' : $phase,
                    'phase' => $phase, 'position' => $position++, 'depends_on' => [], 'meta' => $phase === 'migrate' ? ['name' => 'migrate'] : null, 'status' => $stepStatus,
                    'error' => $stepStatus === 'failed' ? 'GET /up returned 500' : null, 'started_at' => $started, 'finished_at' => $done,
                ]);

                if ($started !== null) {
                    $line = match ($phase) {
                        'fetch' => 'Fetched artifact into releases/'.strtoupper(substr($deployment->id, 0, 10)),
                        'prepare' => 'Linked shared paths: storage, .env',
                        'migrate' => "\e[33mINFO\e[0m  Running migrations.  2026_09_20_120000_add_wishlists_table ......... \e[32m12ms DONE\e[0m",
                        'activate' => 'Switched current -> new release',
                        'restart' => 'Reloaded php-fpm and 2 queue workers',
                        default => $stepStatus === 'failed' ? "\e[31mGET /up -> 500 Internal Server Error\e[0m" : 'GET /up -> 200 OK (38ms)',
                    };
                    OutputLine::query()->create([
                        'deployment_id' => $deployment->id, 'step_id' => $step->id, 'server_id' => $server->id, 'server_name' => $server->name, 'phase' => $phase,
                        'stream' => $stepStatus === 'failed' ? 'stderr' : 'stdout', 'data' => $line."\n", 'at' => $started,
                    ]);
                }
            }
        }
    }
}
