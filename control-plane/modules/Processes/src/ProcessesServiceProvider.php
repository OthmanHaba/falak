<?php

namespace Kiln\Processes;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Contracts\Severity;
use Kiln\Edge\Events\EdgeApplied;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Processes\Application\Jobs\PollProcessStatus;
use Kiln\Processes\Application\Listeners\ConvergeOnSiteChanges;
use Kiln\Processes\Application\Listeners\DeleteOrganizationProcesses;
use Kiln\Processes\Application\Listeners\ForgetDeletedServer;
use Kiln\Processes\Application\Listeners\HandleCommandOutcome;
use Kiln\Processes\Application\Listeners\StopDrainedOctane;
use Kiln\Processes\Contracts\OctaneRouting;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Processes\Contracts\ScheduleDirectory;
use Kiln\Processes\Contracts\ScheduleSources;
use Kiln\Processes\Events\ProgramCrashLooping;
use Kiln\Processes\Events\ProgramRecovered;
use Kiln\Processes\Infrastructure\AgentProcessControl;
use Kiln\Processes\Infrastructure\EloquentOctaneRouting;
use Kiln\Processes\Infrastructure\NoScheduleSources;
use Kiln\Processes\Infrastructure\StateScheduleDirectory;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetReady;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Sites\Events\SiteUpdated;

class ProcessesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ScheduleDirectory::class => StateScheduleDirectory::class,
        ScheduleSources::class => NoScheduleSources::class,
        OctaneRouting::class => EloquentOctaneRouting::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/processes.php', 'processes');

        // Not a singleton: tests swap the AgentGateway after boot.
        $this->app->bind(ProcessControl::class, AgentProcessControl::class);
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('processes.view', [Role::Admin, Role::Developer, Role::Viewer], 'View queue workers, daemons, scheduled jobs and their status', 'processes');
        $registry->register('processes.manage', [Role::Admin, Role::Developer], 'Manage queue workers, daemons and scheduled jobs; restart processes', 'processes');

        $types = $this->app->make(AlertTypes::class);
        $types->register(ProgramCrashLooping::ALERT_TYPE, 'Process keeps crashing', 'Processes', Severity::Critical);
        $types->register(ProgramRecovered::ALERT_TYPE, 'Process running again', 'Processes', Severity::Info);

        Event::listen(SiteCreated::class, [ConvergeOnSiteChanges::class, 'created']);
        Event::listen(SiteUpdated::class, [ConvergeOnSiteChanges::class, 'updated']);
        Event::listen(SiteTargetsChanged::class, [ConvergeOnSiteChanges::class, 'targetsChanged']);
        Event::listen(SiteTargetReady::class, [ConvergeOnSiteChanges::class, 'targetReady']);
        Event::listen(SiteDeleted::class, [ConvergeOnSiteChanges::class, 'deleted']);
        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        Event::listen(EdgeApplied::class, StopDrainedOctane::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationProcesses::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $minutes = (int) config('processes.status_poll_minutes', 5);

            if ($minutes > 0) {
                $schedule->job(new PollProcessStatus)->cron($minutes === 1 ? '* * * * *' : "*/{$minutes} * * * *")->name('processes:status')->withoutOverlapping();
            }
        });
    }
}
