<?php

namespace Kiln\Deployments;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Contracts\Severity;
use Kiln\Builds\Events\BuildCancelled;
use Kiln\Builds\Events\BuildFailed;
use Kiln\Builds\Events\BuildOutputReceived;
use Kiln\Builds\Events\BuildSucceeded;
use Kiln\Deployments\Application\Jobs\ReconcileDeployments;
use Kiln\Deployments\Application\Listeners\DeployOnPush;
use Kiln\Deployments\Application\Listeners\DeploySplitSitesFirst;
use Kiln\Deployments\Application\Listeners\ForgetDeletedResources;
use Kiln\Deployments\Application\Listeners\HandleBuildEvents;
use Kiln\Deployments\Application\Listeners\HandleCommandOutcome;
use Kiln\Deployments\Application\Listeners\RecordBuildOutput;
use Kiln\Deployments\Application\Listeners\RecordCommandOutput;
use Kiln\Deployments\Application\Listeners\RedeployOnPortChange;
use Kiln\Deployments\Application\Listeners\ResumeWaitingDeployments;
use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Deployments\Contracts\DeploymentTrigger;
use Kiln\Deployments\Contracts\FunctionSources;
use Kiln\Deployments\Contracts\LiveReleases;
use Kiln\Deployments\Contracts\RetainedImages;
use Kiln\Deployments\Domain\Policies\DeploymentPermissions;
use Kiln\Deployments\Events\DeploymentFailed;
use Kiln\Deployments\Events\DeploymentSucceeded;
use Kiln\Deployments\Http\Channels\DeploymentChannel;
use Kiln\Deployments\Http\Channels\SiteDeploymentsChannel;
use Kiln\Deployments\Infrastructure\ActionDeploymentTrigger;
use Kiln\Deployments\Infrastructure\DeploymentSiteFields;
use Kiln\Deployments\Infrastructure\EloquentDeploymentDirectory;
use Kiln\Deployments\Infrastructure\EloquentLiveReleases;
use Kiln\Deployments\Infrastructure\EloquentRetainedImages;
use Kiln\Deployments\Infrastructure\NoFunctionSources;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Fleet\Events\CommandOutputReceived;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Sites\Contracts\SiteResourceExtension;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetFailed;
use Kiln\Sites\Events\SiteTargetReady;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Sites\Events\SiteUpdated;
use Kiln\SourceControl\Events\PushReceived;

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
        Event::listen(DeploymentSucceeded::class, [DeploySplitSitesFirst::class, 'onSiteSucceeded']);
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
