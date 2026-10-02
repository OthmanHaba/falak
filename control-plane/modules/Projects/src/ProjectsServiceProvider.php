<?php

namespace Kiln\Projects;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Databases\Events\DatabaseCreated;
use Kiln\Databases\Events\DatabaseDeleted;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationCreated;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Kernel\Support\SharedProps;
use Kiln\Projects\Application\Canvas\KilnNavigation;
use Kiln\Projects\Application\Console\BackfillProjectsCommand;
use Kiln\Projects\Application\Listeners\CreateDefaultProject;
use Kiln\Projects\Application\Listeners\DeleteOrganizationProjects;
use Kiln\Projects\Application\Listeners\PlaceCreatedServices;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\VariableReferences;
use Kiln\Projects\Domain\Models\Project;
use Kiln\Projects\Domain\Policies\ProjectPolicy;
use Kiln\Projects\Infrastructure\EloquentProjectDirectory;
use Kiln\Projects\Infrastructure\ReferenceResolver;
use Kiln\Sites\Events\ComposeServiceExtracted;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;

class ProjectsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ProjectDirectory::class => EloquentProjectDirectory::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        // Stateful per resolution run.
        VariableReferences::class => ReferenceResolver::class,
    ];

    protected function bootModule(): void
    {
        Gate::policy(Project::class, ProjectPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(ProjectPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View projects, environments and their canvas', 'projects');
        $registry->register(ProjectPolicy::MANAGE, [Role::Admin, Role::Developer], 'Create, rename and delete projects and environments; arrange and create services on the canvas', 'projects');

        Event::listen(OrganizationCreated::class, CreateDefaultProject::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationProjects::class);
        Event::listen(SiteCreated::class, [PlaceCreatedServices::class, 'siteCreated']);
        Event::listen(SiteDeleted::class, [PlaceCreatedServices::class, 'siteDeleted']);
        Event::listen(DatabaseCreated::class, [PlaceCreatedServices::class, 'databaseCreated']);
        Event::listen(ComposeServiceExtracted::class, [PlaceCreatedServices::class, 'composeServiceExtracted']);
        Event::listen(DatabaseDeleted::class, [PlaceCreatedServices::class, 'databaseDeleted']);

        // app(), not $this->app: under the FrankenPHP worker the latter is the base app, not the request sandbox.
        $this->app->make(SharedProps::class)->register('kiln', fn (Request $request) => app(KilnNavigation::class)->for($request));

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillProjectsCommand::class]);
        }
    }
}
