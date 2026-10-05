<?php

namespace Falak\Identity;

use Falak\Identity\Application\Console\CreateAdminCommand;
use Falak\Identity\Application\Console\SyncPermissionsCommand;
use Falak\Identity\Application\Listeners\SyncPermissionsAfterMigrations;
use Falak\Identity\Application\Registration;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\Organization;
use Falak\Identity\Domain\Models\PersonalAccessToken;
use Falak\Identity\Domain\Models\User;
use Falak\Identity\Domain\Policies\OrganizationPolicy;
use Falak\Identity\Http\Middleware\EnsureCurrentOrganization;
use Falak\Identity\Http\Middleware\EnsureOrganizationPermission;
use Falak\Identity\Infrastructure\DatabaseAuditLog;
use Falak\Identity\Infrastructure\EloquentOrganizationDirectory;
use Falak\Identity\Infrastructure\InMemoryPermissionRegistry;
use Falak\Identity\Infrastructure\ResolvedCurrentOrganization;
use Falak\Identity\Infrastructure\SpatieOrganizationAccess;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Kernel\Support\SharedProps;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;

class IdentityServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        PermissionRegistry::class => InMemoryPermissionRegistry::class,
        OrganizationDirectory::class => EloquentOrganizationDirectory::class,
    ];

    public function register(): void
    {
        // Identity owns the auth routes; Fortify is used for its 2FA actions only.
        Fortify::ignoreRoutes();

        $this->mergeConfigFrom($this->modulePath().'/config/identity.php', 'identity');

        // Request-scoped: reset between requests (Octane) and jobs.
        $this->app->scoped(ResolvedCurrentOrganization::class);
        $this->app->scoped(CurrentOrganization::class, ResolvedCurrentOrganization::class);
        $this->app->scoped(SpatieOrganizationAccess::class);
        $this->app->scoped(OrganizationAccess::class, SpatieOrganizationAccess::class);
        $this->app->scoped(AuditLog::class, DatabaseAuditLog::class);
    }

    protected function bootModule(): void
    {
        Relation::morphMap(['user' => User::class]);
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Gate::policy(Organization::class, OrganizationPolicy::class);

        /** @var Router $router */
        $router = $this->app->make('router');
        $router->aliasMiddleware('org', EnsureCurrentOrganization::class);
        $router->aliasMiddleware('org.can', EnsureOrganizationPermission::class);

        $this->registerPermissions($this->app->make(PermissionRegistry::class));

        Event::listen(MigrationsEnded::class, SyncPermissionsAfterMigrations::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class, CreateAdminCommand::class]);
        }

        $this->shareInertiaProps();
    }

    private function registerPermissions(PermissionRegistry $registry): void
    {
        $registry->register('organization.update', [Role::Admin], 'Rename the organization', 'organization');
        $registry->register('organization.delete', [], 'Delete the organization', 'organization');
        $registry->register('members.view', [Role::Admin, Role::Developer, Role::Viewer], 'View members and teams', 'members');
        $registry->register('members.manage', [Role::Admin], 'Invite, remove and change roles of members', 'members');
        $registry->register('teams.manage', [Role::Admin], 'Create teams and manage their members', 'members');
        $registry->register('audit.view', [Role::Admin], 'View the audit log', 'audit');
    }

    /**
     * Shared props for every Inertia page: current organization, switcher list and permissions.
     *
     * Registered on the boot-time {@see SharedProps} registry rather than with `Inertia::share()`: under the
     * FrankenPHP worker (Octane) Inertia's shared props are flushed before every request. Everything is
     * resolved from the current container (`app()`), never from `$this->app`, which under the worker is the
     * long-lived base application instead of the per-request sandbox.
     */
    private function shareInertiaProps(): void
    {
        $this->app->make(SharedProps::class)->register('organization', function (Request $request) {
            $user = $request->user();

            if (! $user instanceof User) {
                return null;
            }

            $current = app(ResolvedCurrentOrganization::class)->model();
            $access = app(SpatieOrganizationAccess::class);

            return [
                'current' => $current ? [
                    ...$current->toData()->toArray(),
                    'role' => $access->roleOf($user->id, $current->id)?->value,
                    'permissions' => $access->permissionsOf($user, $current->id),
                ] : null,
                'all' => $user->organizations()->orderBy('name')->get(['identity_organizations.id', 'name', 'slug', 'personal'])
                    ->map(fn (Organization $organization) => [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'slug' => $organization->slug,
                        'personal' => $organization->personal,
                    ])->values(),
            ];
        }, authenticated: false);

        // Guests only: whether the login and welcome pages offer "Sign up" (open, invite or closed).
        $this->app->make(SharedProps::class)->register('registration', function (Request $request) {
            return $request->user() ? null : app(Registration::class)->mode();
        }, authenticated: false);
    }
}
