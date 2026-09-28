<?php

namespace Kiln\Telemetry;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Kiln\Deployments\Events\ReleaseActivated;
use Kiln\Fleet\Events\AgentEnrolled;
use Kiln\Fleet\Events\AgentVersionChanged;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationCreated;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Servers\Events\ServerProvisioned;
use Kiln\Sites\Events\SiteCreated;
use Kiln\Sites\Events\SiteDeleted;
use Kiln\Sites\Events\SiteTargetsChanged;
use Kiln\Telemetry\Application\Console\ProvisionGrafanaCommand;
use Kiln\Telemetry\Application\Jobs\DispatchPendingTelemetry;
use Kiln\Telemetry\Application\Listeners\ConfigureTelemetryOnEnrollment;
use Kiln\Telemetry\Application\Listeners\ConfigureTelemetryOnProvisioned;
use Kiln\Telemetry\Application\Listeners\ForgetOrganizationTelemetry;
use Kiln\Telemetry\Application\Listeners\ProvisionGrafanaForOrganization;
use Kiln\Telemetry\Application\Listeners\ReconfigureAfterAgentUpgrade;
use Kiln\Telemetry\Application\Listeners\ReconfigureOnReleaseActivated;
use Kiln\Telemetry\Application\Listeners\ReconfigureOnSiteChanges;
use Kiln\Telemetry\Contracts\AccessLogs;
use Kiln\Telemetry\Contracts\Annotations;
use Kiln\Telemetry\Contracts\LogsQuery;
use Kiln\Telemetry\Contracts\MetricsBackend;
use Kiln\Telemetry\Contracts\ServerSites;
use Kiln\Telemetry\Contracts\TelemetryConfigurator;
use Kiln\Telemetry\Contracts\TelemetryLinks;
use Kiln\Telemetry\Contracts\TracesQuery;
use Kiln\Telemetry\Infrastructure\AgentTelemetryConfigurator;
use Kiln\Telemetry\Infrastructure\DefaultTelemetryLinks;
use Kiln\Telemetry\Infrastructure\Grafana\GrafanaAnnotations;
use Kiln\Telemetry\Infrastructure\Grafana\GrafanaClient;
use Kiln\Telemetry\Infrastructure\LokiAccessLogs;
use Kiln\Telemetry\Infrastructure\LokiLogsQuery;
use Kiln\Telemetry\Infrastructure\Metrics\MimirBackend;
use Kiln\Telemetry\Infrastructure\Metrics\VictoriaMetricsBackend;
use Kiln\Telemetry\Infrastructure\NullServerSites;
use Kiln\Telemetry\Infrastructure\TempoTracesQuery;

class TelemetryServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        TelemetryLinks::class => DefaultTelemetryLinks::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/telemetry.php', 'telemetry');

        // Clients read config on resolution (cheap), so config changes (tests, settings) apply immediately.
        $this->app->bind(MetricsBackend::class, fn () => config('telemetry.metrics.backend') === 'mimir'
            ? MimirBackend::fromConfig()
            : VictoriaMetricsBackend::fromConfig());
        $this->app->bind(LogsQuery::class, fn () => LokiLogsQuery::fromConfig());
        $this->app->bind(TracesQuery::class, fn () => TempoTracesQuery::fromConfig());
        $this->app->bind(GrafanaClient::class, fn () => GrafanaClient::fromConfig());
        $this->app->bind(Annotations::class, GrafanaAnnotations::class);
        $this->app->bind(TelemetryConfigurator::class, AgentTelemetryConfigurator::class);
        $this->app->bind(AccessLogs::class, LokiAccessLogs::class);

        // Sites registers before Telemetry and binds its own ServerSites; only fill the gap.
        $this->app->singletonIf(ServerSites::class, NullServerSites::class);
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('telemetry.view', [Role::Admin, Role::Developer, Role::Viewer], 'View server metrics, logs and traces', 'telemetry');
        $registry->register('telemetry.manage', [Role::Admin], 'Configure telemetry endpoints and provision Grafana', 'telemetry');

        Event::listen(AgentEnrolled::class, ConfigureTelemetryOnEnrollment::class);
        Event::listen(AgentVersionChanged::class, ReconfigureAfterAgentUpgrade::class);
        Event::listen(ReleaseActivated::class, ReconfigureOnReleaseActivated::class);
        Event::listen(ServerProvisioned::class, ConfigureTelemetryOnProvisioned::class);
        Event::listen(OrganizationCreated::class, ProvisionGrafanaForOrganization::class);
        Event::listen(OrganizationDeleted::class, ForgetOrganizationTelemetry::class);
        Event::listen([SiteCreated::class, SiteTargetsChanged::class, SiteDeleted::class], ReconfigureOnSiteChanges::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new DispatchPendingTelemetry)->everyMinute()->name('telemetry:pending-configure')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionGrafanaCommand::class]);
        }
    }
}
