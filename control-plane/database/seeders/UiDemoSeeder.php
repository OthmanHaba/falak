<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
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
        ]));

        $sites = [
            ['Storefront', 'storefront', SiteRuntime::FrankenPhp, Framework::Laravel, 'acme/storefront'],
            ['Marketing', 'marketing', SiteRuntime::Node, Framework::Next, 'acme/marketing-site'],
            ['Docs', 'docs', SiteRuntime::Static, Framework::Static, 'acme/docs'],
            ['Blog', 'blog', SiteRuntime::PhpFpm, Framework::WordPress, null],
        ];

        foreach ($sites as $index => [$name, $slug, $runtime, $framework, $repository]) {
            $site = Site::query()->create([
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

        // Place the sites into the organization's Default project, and add a second project with staging so the
        // project/environment switchers have something to switch between.
        Artisan::call('projects:backfill', ['--organization' => $organization->id]);
        $platform = app(CreateProject::class)($organization->id, $admin->id, ['name' => 'Platform']);
        app(CreateEnvironment::class)($platform, 'staging', $admin->id);

        $this->callWith(InfrastructureDemoSeeder::class, ['organizationId' => $organization->id, 'userId' => $admin->id]);
    }
}
