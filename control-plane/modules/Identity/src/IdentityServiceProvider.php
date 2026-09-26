<?php

namespace Kiln\Identity;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Kiln\Identity\Application\Console\SyncPermissionsCommand;
use Kiln\Identity\Application\Listeners\SyncPermissionsAfterMigrations;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\CurrentOrganization;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Identity\Contracts\OrganizationDirectory;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Domain\Models\Organization;
use Kiln\Identity\Domain\Models\PersonalAccessToken;
use Kiln\Identity\Domain\Models\User;
use Kiln\Identity\Domain\Policies\OrganizationPolicy;
use Kiln\Identity\Http\Middleware\EnsureCurrentOrganization;
use Kiln\Identity\Http\Middleware\EnsureOrganizationPermission;
use Kiln\Identity\Infrastructure\DatabaseAuditLog;
use Kiln\Identity\Infrastructure\EloquentOrganizationDirectory;
use Kiln\Identity\Infrastructure\InMemoryPermissionRegistry;
use Kiln\Identity\Infrastructure\ResolvedCurrentOrganization;
use Kiln\Identity\Infrastructure\SpatieOrganizationAccess;
use Kiln\Kernel\Support\ModuleServiceProvider;
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
            $this->commands([SyncPermissionsCommand::class]);
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
     */
    private function shareInertiaProps(): void
    {
        Inertia::share('organization', function (Request $request) {
            $user = $request->user();

            if (! $user instanceof User) {
                return null;
            }

            $current = $this->app->make(ResolvedCurrentOrganization::class)->model();
            $access = $this->app->make(SpatieOrganizationAccess::class);

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
        });
    }
}
