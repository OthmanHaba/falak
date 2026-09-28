<?php

namespace Kiln\SourceControl;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\SourceControl\Application\Listeners\DeleteOrganizationConnections;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use Kiln\SourceControl\Domain\Models\Connection;
use Kiln\SourceControl\Domain\Policies\ConnectionPolicy;
use Kiln\SourceControl\Infrastructure\EloquentSourceControlGateway;

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
