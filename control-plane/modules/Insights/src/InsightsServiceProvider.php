<?php

namespace Kiln\Insights;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Fleet\Events\InsightsReceived;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Insights\Application\Jobs\DetectMissedHeartbeats;
use Kiln\Insights\Application\Jobs\EvaluateThresholdsJob;
use Kiln\Insights\Application\Jobs\PruneInsightsJob;
use Kiln\Insights\Application\Listeners\DeleteOrganizationInsights;
use Kiln\Insights\Application\Listeners\IngestReceivedInsights;
use Kiln\Insights\Contracts\IssueDirectory;
use Kiln\Insights\Contracts\SiteNameResolver;
use Kiln\Insights\Domain\Models\HeartbeatMonitor;
use Kiln\Insights\Domain\Models\Issue;
use Kiln\Insights\Domain\Models\Threshold;
use Kiln\Insights\Domain\Policies\OrganizationScopedPolicy;
use Kiln\Insights\Infrastructure\EloquentIssueDirectory;
use Kiln\Insights\Infrastructure\IdSiteNameResolver;
use Kiln\Kernel\Support\ModuleServiceProvider;

class InsightsServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        IssueDirectory::class => EloquentIssueDirectory::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/insights.php', 'insights');

        // Default only: the Sites module (registered earlier) binds the real resolver, which must win.
        $this->app->singletonIf(SiteNameResolver::class, IdSiteNameResolver::class);
    }

    protected function bootModule(): void
    {
        Gate::policy(Issue::class, OrganizationScopedPolicy::class);
        Gate::policy(Threshold::class, OrganizationScopedPolicy::class);
        Gate::policy(HeartbeatMonitor::class, OrganizationScopedPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('insights.view', [Role::Admin, Role::Developer, Role::Viewer], 'View application insights, issues and scheduled task health', 'insights');
        $registry->register('insights.manage', [Role::Admin, Role::Developer], 'Resolve, assign and comment on issues; configure thresholds and heartbeats', 'insights');

        Event::listen(InsightsReceived::class, IngestReceivedInsights::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationInsights::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new EvaluateThresholdsJob)->everyMinute()->name('insights:thresholds')->withoutOverlapping();
            $schedule->job(new DetectMissedHeartbeats)->everyMinute()->name('insights:heartbeats')->withoutOverlapping();
            $schedule->job(new PruneInsightsJob)->dailyAt('03:15')->name('insights:prune')->withoutOverlapping();
        });
    }
}
