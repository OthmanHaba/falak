<?php

namespace Kiln\Sites;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Insights\Contracts\SiteNameResolver;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Sites\Application\Listeners\DeleteOrganizationSites;
use Kiln\Sites\Application\Listeners\DetachSourceConnection;
use Kiln\Sites\Application\Listeners\HandleCommandOutcome;
use Kiln\Sites\Application\Listeners\RemoveServerTargets;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteDomains;
use Kiln\Sites\Contracts\SiteHeaders;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Policies\SitePolicy;
use Kiln\Sites\Infrastructure\EloquentServerSites;
use Kiln\Sites\Infrastructure\EloquentSiteDirectory;
use Kiln\Sites\Infrastructure\EloquentSiteHeaders;
use Kiln\Sites\Infrastructure\EloquentSiteNameResolver;
use Kiln\Sites\Infrastructure\NullSiteDomains;
use Kiln\SourceControl\Events\ConnectionDeleted;
use Kiln\Telemetry\Contracts\ServerSites;

class SitesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        SiteDirectory::class => EloquentSiteDirectory::class,
        SiteHeaders::class => EloquentSiteHeaders::class,
        // Insights and Telemetry register later and only fill these when unbound.
        SiteNameResolver::class => EloquentSiteNameResolver::class,
        ServerSites::class => EloquentServerSites::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/sites.php', 'sites');

        // Edge owns domains and rebinds this (it boots after Sites).
        if (! $this->app->bound(SiteDomains::class)) {
            $this->app->singleton(SiteDomains::class, NullSiteDomains::class);
        }
    }

    protected function bootModule(): void
    {
        Gate::policy(Site::class, SitePolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('sites.view', [Role::Admin, Role::Developer, Role::Viewer], 'View sites, their settings and command history', 'sites');
        $registry->register('sites.create', [Role::Admin, Role::Developer], 'Create sites', 'sites');
        $registry->register('sites.manage', [Role::Admin, Role::Developer], 'Change site settings, servers, deploy scripts and Laravel toggles', 'sites');
        $registry->register('sites.delete', [Role::Admin], 'Delete sites', 'sites');
        $registry->register('sites.env.view', [Role::Admin, Role::Developer], 'Reveal site environment variables', 'sites');
        $registry->register('sites.env.manage', [Role::Admin, Role::Developer], 'Edit site environment variables', 'sites');
        $registry->register('sites.commands.run', [Role::Admin, Role::Developer], 'Run commands in a site on its servers', 'sites');

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerDeleted::class, RemoveServerTargets::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationSites::class);
        Event::listen(ConnectionDeleted::class, DetachSourceConnection::class);
    }
}
