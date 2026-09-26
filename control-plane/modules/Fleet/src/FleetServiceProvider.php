<?php

namespace Kiln\Fleet;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Kiln\Fleet\Application\Actions\IssueInstallToken;
use Kiln\Fleet\Application\Console\CaInitCommand;
use Kiln\Fleet\Application\Console\CaServerCertificateCommand;
use Kiln\Fleet\Application\Jobs\SweepFleet;
use Kiln\Fleet\Contracts\AgentDirectory;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Enrollment;
use Kiln\Fleet\Http\Channels\CommandChannel;
use Kiln\Fleet\Infrastructure\AgentBinaries;
use Kiln\Fleet\Infrastructure\EloquentAgentDirectory;
use Kiln\Fleet\Infrastructure\FleetAgentGateway;
use Kiln\Fleet\Infrastructure\FleetEnrollment;
use Kiln\Fleet\Infrastructure\InstallScript;
use Kiln\Fleet\Infrastructure\PanelUrls;
use Kiln\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Fleet\Infrastructure\Signals\CommandSignal;
use Kiln\Fleet\Infrastructure\Signals\DatabaseCommandSignal;
use Kiln\Fleet\Infrastructure\Signals\RedisCommandSignal;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Kernel\Support\ModuleServiceProvider;

class FleetServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        AgentGateway::class => FleetAgentGateway::class,
        AgentDirectory::class => EloquentAgentDirectory::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/fleet.php', 'fleet');

        // Request-scoped: depends on the (request-scoped) AuditLog.
        $this->app->scoped(Enrollment::class, FleetEnrollment::class);

        $this->app->singleton(ProtocolSchemas::class, fn () => new ProtocolSchemas((string) config('fleet.schemas_path')));

        $this->app->singleton(CertificateAuthorityService::class, fn ($app) => new CertificateAuthorityService(
            $app->make('cache.store'),
            (string) config('fleet.ca_path'),
            (int) config('fleet.ca_validity_years', 10),
            (int) config('fleet.cert_validity_days', 90),
        ));

        $this->app->singleton(PanelUrls::class, fn ($app) => new PanelUrls(
            $app->make('url'),
            config('fleet.panel_url'),
            config('fleet.api_url'),
            config('fleet.agent.download_url'),
        ));

        $this->app->singleton(AgentBinaries::class, fn () => new AgentBinaries((string) config('fleet.agent.binaries_path')));

        $this->app->bind(InstallScript::class, fn ($app) => new InstallScript(
            $app->make(PanelUrls::class),
            $app->make(AgentBinaries::class),
            (array) config('fleet.agent.checksums', []),
        ));

        $this->app->bind(IssueInstallToken::class, fn ($app) => new IssueInstallToken(
            $app->make(PanelUrls::class),
            $app->make(AuditLog::class),
            (int) config('fleet.install_token_ttl_minutes', 1440),
        ));

        $this->app->singleton(CommandSignal::class, fn ($app) => config('fleet.wake_driver') === 'redis'
            ? new RedisCommandSignal($app->make('redis'), (string) config('fleet.wake_redis_connection', 'default'))
            : new DatabaseCommandSignal((int) config('fleet.database_poll_interval_ms', 500)));
    }

    protected function bootModule(): void
    {
        Route::group([], $this->modulePath().'/routes/install.php');

        RateLimiter::for('fleet-enroll', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register('fleet.commands.view', [Role::Admin, Role::Developer, Role::Viewer], 'View agent command output', 'fleet');
        $registry->register('fleet.agents.manage', [Role::Admin], 'Issue install commands and revoke agents', 'fleet');

        Broadcast::channel(CommandChannel::NAME, CommandChannel::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new SweepFleet)->everyMinute()->name('fleet:sweep')->withoutOverlapping();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([CaInitCommand::class, CaServerCertificateCommand::class]);
        }
    }
}
