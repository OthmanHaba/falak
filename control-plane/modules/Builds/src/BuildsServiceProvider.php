<?php

namespace Kiln\Builds;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Contracts\Severity;
use Kiln\Builds\Application\Artifacts\ArtifactStorage;
use Kiln\Builds\Application\Console\RegistryIdleCommand;
use Kiln\Builds\Application\Console\RegistryPruneCommand;
use Kiln\Builds\Application\Jobs\ExpireBuilds;
use Kiln\Builds\Application\Jobs\PruneArtifacts;
use Kiln\Builds\Application\Jobs\PruneRegistry;
use Kiln\Builds\Application\Listeners\ManageServerBuilders;
use Kiln\Builds\Contracts\BuildService;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Builds\Domain\Policies\BuildPolicy;
use Kiln\Builds\Http\Channels\BuildChannel;
use Kiln\Builds\Infrastructure\Artifacts\LocalArtifactStorage;
use Kiln\Builds\Infrastructure\Artifacts\S3ArtifactStorage;
use Kiln\Builds\Infrastructure\Artifacts\SigV4Presigner;
use Kiln\Builds\Infrastructure\EloquentBuildService;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Servers\Events\ServerProvisioned;
use Kiln\Sites\Events\SiteDeleted;

class BuildsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        BuildService::class => EloquentBuildService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/builds.php', 'builds');

        $this->app->singleton(ArtifactStorage::class, function ($app) {
            $config = (array) config('builds.artifacts');

            if (($config['driver'] ?? 'local') === 's3') {
                $s3 = (array) $config['s3'];

                return new S3ArtifactStorage(
                    new SigV4Presigner((string) $s3['key'], (string) $s3['secret'], (string) ($s3['region'] ?: 'us-east-1')),
                    $app->make(HttpFactory::class),
                    (string) ($s3['endpoint'] ?: 'https://s3.'.($s3['region'] ?: 'us-east-1').'.amazonaws.com'),
                    (string) $s3['bucket'],
                    (string) $s3['prefix'],
                    (bool) $s3['path_style'],
                );
            }

            return new LocalArtifactStorage((string) $config['local']['root'], (string) $config['local']['url']);
        });
    }

    protected function bootModule(): void
    {
        Gate::policy(Build::class, BuildPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(BuildPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View builds, build logs and builders', 'builds');
        $registry->register(BuildPolicy::MANAGE, [Role::Admin, Role::Developer], 'Cancel builds and manage builders', 'builds');

        $this->app->make(AlertTypes::class)->register('builds.failed', 'Build failed', 'Builds', Severity::Warning);

        Event::listen(ServerProvisioned::class, [ManageServerBuilders::class, 'provisioned']);
        Event::listen(ServerDeleted::class, [ManageServerBuilders::class, 'serverDeleted']);
        Event::listen(OrganizationDeleted::class, [ManageServerBuilders::class, 'organizationDeleted']);
        Event::listen(SiteDeleted::class, [ManageServerBuilders::class, 'siteDeleted']);

        Broadcast::channel(BuildChannel::NAME, BuildChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new ExpireBuilds)->everyMinute()->name('builds:expire')->withoutOverlapping();
            $schedule->job(new PruneArtifacts)->dailyAt('03:30')->name('builds:prune-artifacts')->withoutOverlapping();
            // After the artifacts prune: images of builds it pruned go too, unless a release may still run them.
            $schedule->job(new PruneRegistry)->dailyAt('03:45')->name('builds:prune-registry')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RegistryPruneCommand::class, RegistryIdleCommand::class]);
        }
    }
}
