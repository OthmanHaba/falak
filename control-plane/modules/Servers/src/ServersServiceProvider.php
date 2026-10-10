<?php

namespace Falak\Servers;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Fleet\Events\AgentCameOnline;
use Falak\Fleet\Events\AgentEnrolled;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Fleet\Events\AgentRevoked;
use Falak\Fleet\Events\AgentWentOffline;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Servers\Application\Jobs\CheckServerHealth;
use Falak\Servers\Application\Listeners\BroadcastConnectivity;
use Falak\Servers\Application\Listeners\DeleteOrganizationServers;
use Falak\Servers\Application\Listeners\HandleCommandOutcome;
use Falak\Servers\Application\Listeners\RecordReportedFacts;
use Falak\Servers\Application\Listeners\StartProvisioningOnEnrollment;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerHeaders;
use Falak\Servers\Contracts\ServerSshKeys;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;
use Falak\Servers\Domain\Policies\ServerPolicy;
use Falak\Servers\Domain\Policies\SshKeyPolicy;
use Falak\Servers\Http\Channels\ServerChannel;
use Falak\Servers\Infrastructure\EloquentServerDirectory;
use Falak\Servers\Infrastructure\EloquentServerSshKeys;
use Falak\Servers\Infrastructure\ProvisioningPlanBuilder;
use Falak\Servers\Infrastructure\ServerHeaderPresenter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class ServersServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        ServerDirectory::class => EloquentServerDirectory::class,
        ServerHeaders::class => ServerHeaderPresenter::class,
        ServerSshKeys::class => EloquentServerSshKeys::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/servers.php', 'servers');

        $this->app->bind(ProvisioningPlanBuilder::class, fn () => new ProvisioningPlanBuilder((array) config('servers')));
    }

    protected function bootModule(): void
    {
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(SshKey::class, SshKeyPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('servers.view', [Role::Admin, Role::Developer, Role::Viewer], 'View servers, metrics and provisioning logs', 'servers');
        $registry->register('servers.create', [Role::Admin, Role::Developer], 'Create servers', 'servers');
        $registry->register('servers.manage', [Role::Admin, Role::Developer], 'Manage PHP, settings, SSH keys and re-provision servers', 'servers');
        $registry->register('servers.delete', [Role::Admin], 'Delete servers', 'servers');
        $registry->register('ssh_keys.manage', [Role::Admin, Role::Developer], 'Add and remove organization SSH keys', 'servers');

        Event::listen(AgentEnrolled::class, StartProvisioningOnEnrollment::class);
        Event::listen(AgentFactsReported::class, RecordReportedFacts::class);
        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen([AgentWentOffline::class, AgentCameOnline::class, AgentRevoked::class], BroadcastConnectivity::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationServers::class);

        Broadcast::channel(ServerChannel::NAME, ServerChannel::class);

        $types = $this->app->make(AlertTypes::class);
        $types->register('servers.disk_usage', 'Disk almost full (80% / 90%)', 'Servers', Severity::Warning);
        $types->register('servers.disk_forecast', 'Disk fills within 48 hours', 'Servers', Severity::Warning);
        $types->register('servers.memory_high', 'Memory above 90% for 10 minutes', 'Servers', Severity::Warning);
        $types->register('servers.cpu_high', 'CPU above 90% for 15 minutes', 'Servers', Severity::Warning);
        $types->register('servers.load_high', 'Load above twice the CPUs for 15 minutes', 'Servers', Severity::Warning);
        $types->register('servers.reboot_required', 'Server needs a reboot', 'Servers', Severity::Warning);
        $types->register('servers.agent_outdated', 'Agent outdated for an hour', 'Servers', Severity::Warning, 'Update agent');

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new CheckServerHealth)->everyMinute()->name('servers:health')->withoutOverlapping(5)
                ->when(fn () => now()->minute % 15 !== 0);
            // Every 15 minutes the same check also forecasts when disks fill (six hours of samples).
            $schedule->job(new CheckServerHealth(forecast: true))->everyFifteenMinutes()->name('servers:health-forecast')->withoutOverlapping(15);
        });
    }
}
