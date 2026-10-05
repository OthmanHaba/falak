<?php

namespace Falak\SourceControl;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\SourceControl\Application\Listeners\DeleteOrganizationConnections;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Policies\ConnectionPolicy;
use Falak\SourceControl\Infrastructure\EloquentSourceControlGateway;

class SourceControlServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/source_control.php', 'source_control');

        // Request-scoped: depends on the (request-scoped) AuditLog.
        $this->app->scoped(SourceControlGateway::class, EloquentSourceControlGateway::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(Connection::class, ConnectionPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('source_control.view', [Role::Admin, Role::Developer, Role::Viewer], 'View source control connections, repositories and pushes', 'source_control');
        $registry->register('source_control.manage', [Role::Admin], 'Connect and disconnect git providers', 'source_control');

        RateLimiter::for('source-control-webhooks', fn (Request $request) => Limit::perMinute(max(1, (int) config('source_control.webhook_rate_limit', 120)))
            ->by('webhook:'.(string) $request->route('webhook')));
        RateLimiter::for('source-control-github-app-webhooks', fn (Request $request) => Limit::perMinute(max(1, (int) config('source_control.github_app_webhook_rate_limit', 600)))
            ->by('github-app:'.(string) $request->route('app')));

        Event::listen(OrganizationDeleted::class, DeleteOrganizationConnections::class);
    }
}
