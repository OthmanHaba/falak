<?php

namespace Falak\Deployments;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Builds\Events\BuildCancelled;
use Falak\Builds\Events\BuildFailed;
use Falak\Builds\Events\BuildOutputReceived;
use Falak\Builds\Events\BuildSucceeded;
use Falak\Deployments\Application\Jobs\ReconcileDeployments;
use Falak\Deployments\Application\Listeners\DeployOnPush;
use Falak\Deployments\Application\Listeners\DeploySplitSitesFirst;
use Falak\Deployments\Application\Listeners\ForgetDeletedResources;
use Falak\Deployments\Application\Listeners\HandleBuildEvents;
use Falak\Deployments\Application\Listeners\HandleCommandOutcome;
use Falak\Deployments\Application\Listeners\RecordBuildOutput;
use Falak\Deployments\Application\Listeners\RecordCommandOutput;
use Falak\Deployments\Application\Listeners\RedeployOnPortChange;
use Falak\Deployments\Application\Listeners\ResumeWaitingDeployments;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Deployments\Contracts\FunctionSources;
use Falak\Deployments\Contracts\LiveReleases;
use Falak\Deployments\Contracts\RetainedImages;
use Falak\Deployments\Domain\Policies\DeploymentPermissions;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Deployments\Http\Channels\DeploymentChannel;
use Falak\Deployments\Http\Channels\SiteDeploymentsChannel;
use Falak\Deployments\Infrastructure\ActionDeploymentTrigger;
use Falak\Deployments\Infrastructure\DeploymentSiteFields;
use Falak\Deployments\Infrastructure\EloquentDeploymentDirectory;
use Falak\Deployments\Infrastructure\EloquentLiveReleases;
use Falak\Deployments\Infrastructure\EloquentRetainedImages;
use Falak\Deployments\Infrastructure\NoFunctionSources;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Fleet\Events\CommandOutputReceived;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Sites\Contracts\SiteResourceExtension;
use Falak\Sites\Events\SiteDeleted;
use Falak\Sites\Events\SiteTargetFailed;
use Falak\Sites\Events\SiteTargetReady;
use Falak\Sites\Events\SiteTargetsChanged;
use Falak\Sites\Events\SiteUpdated;
use Falak\SourceControl\Events\PushReceived;

class DeploymentsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        DeploymentDirectory::class => EloquentDeploymentDirectory::class,
        LiveReleases::class => EloquentLiveReleases::class,
        RetainedImages::class => EloquentRetainedImages::class,
        FunctionSources::class => NoFunctionSources::class,
    ];

    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        DeploymentTrigger::class => ActionDeploymentTrigger::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/deployments.php', 'deployments');

        $this->app->tag([DeploymentSiteFields::class], SiteResourceExtension::TAG);
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(DeploymentPermissions::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View deployments, their output and releases', 'deployments');
        $registry->register(DeploymentPermissions::CREATE, [Role::Admin, Role::Developer], 'Deploy sites and cancel queued or waiting deployments', 'deployments');
        $registry->register(DeploymentPermissions::ROLLBACK, [Role::Admin, Role::Developer], 'Roll sites back to an earlier release', 'deployments');
        $registry->register(DeploymentPermissions::MANAGE, [Role::Admin, Role::Developer], 'Change deployment strategy, health checks, retention, push-to-deploy and deploy hooks', 'deployments');

        $types = $this->app->make(AlertTypes::class);
        $types->register('deployments.failed', 'Deployment failed', 'Deployments', Severity::Critical);
        $types->register('deployments.rolled_back', 'Site rolled back', 'Deployments', Severity::Warning);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(CommandOutputReceived::class, RecordCommandOutput::class);
        Event::listen(BuildSucceeded::class, [HandleBuildEvents::class, 'succeeded']);
        Event::listen(BuildFailed::class, [HandleBuildEvents::class, 'failed']);
        Event::listen(BuildCancelled::class, [HandleBuildEvents::class, 'cancelled']);
        Event::listen(BuildOutputReceived::class, RecordBuildOutput::class);
        Event::listen(PushReceived::class, DeployOnPush::class);
        Event::listen(SiteUpdated::class, RedeployOnPortChange::class);
        Event::listen(DeploymentFailed::class, [DeploySplitSitesFirst::class, 'onStackFailed']);
        Event::listen(DeploymentSucceeded::class, [DeploySplitSitesFirst::class, 'onSucceeded']);
        Event::listen(SiteDeleted::class, [ForgetDeletedResources::class, 'siteDeleted']);
        Event::listen([SiteTargetReady::class, SiteTargetFailed::class, SiteTargetsChanged::class], ResumeWaitingDeployments::class);
        Event::listen(OrganizationDeleted::class, [ForgetDeletedResources::class, 'organizationDeleted']);

        Broadcast::channel(DeploymentChannel::NAME, DeploymentChannel::class);
        Broadcast::channel(SiteDeploymentsChannel::NAME, SiteDeploymentsChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new ReconcileDeployments)->everyMinute()->name('deployments:reconcile')->withoutOverlapping();
        });
    }
}
