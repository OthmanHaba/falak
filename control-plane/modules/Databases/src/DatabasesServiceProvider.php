<?php

namespace Falak\Databases;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Databases\Application\Jobs\RunDueBackups;
use Falak\Databases\Application\Listeners\ConvergeKeyValueNetworkOnChange;
use Falak\Databases\Application\Listeners\DeleteOrganizationData;
use Falak\Databases\Application\Listeners\EnableContainerAccessOnUpgrade;
use Falak\Databases\Application\Listeners\ForgetDeletedServer;
use Falak\Databases\Application\Listeners\ForgetFailedEngine;
use Falak\Databases\Application\Listeners\HandleCommandOutcome;
use Falak\Databases\Application\Listeners\SyncDatabaseEngine;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Events\RestoreFinished;
use Falak\Databases\Infrastructure\ActionDatabaseProvisioner;
use Falak\Databases\Infrastructure\DatabaseContainerPorts;
use Falak\Databases\Infrastructure\EloquentDatabaseConnections;
use Falak\Databases\Infrastructure\EloquentDatabaseDirectory;
use Falak\Databases\Infrastructure\ObjectStorageBackupStorage;
use Falak\Fleet\Events\AgentFactsReported;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Network\Contracts\ContainerHostPorts;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Projects\Events\ServiceLinked;
use Falak\Projects\Events\ServiceUnlinked;
use Falak\Servers\Events\DatabaseEngineInstalled;
use Falak\Servers\Events\DatabaseEngineInstallFailed;
use Falak\Servers\Events\ServerDeleted;
use Falak\Servers\Events\ServerProvisioned;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class DatabasesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        DatabaseDirectory::class => EloquentDatabaseDirectory::class,
        DatabaseConnections::class => EloquentDatabaseConnections::class,
        DatabaseProvisioner::class => ActionDatabaseProvisioner::class,
        ContainerHostPorts::class => DatabaseContainerPorts::class,
        BackupStorage::class => ObjectStorageBackupStorage::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/databases.php', 'databases');

    }

    protected function bootModule(): void
    {
        foreach ([DatabaseServer::class, Database::class, DatabaseUser::class, StorageProvider::class, BackupSchedule::class, Backup::class, Restore::class] as $model) {
            Gate::policy($model, DatabasesPolicy::class);
        }

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(DatabasesPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View database servers, databases, users and backups', 'databases');
        $registry->register(DatabasesPolicy::MANAGE, [Role::Admin, Role::Developer], 'Create and drop databases and users, manage backup schedules and run backups', 'databases');
        $registry->register(DatabasesPolicy::REVEAL, [Role::Admin, Role::Developer], 'Reveal database user passwords', 'databases');
        $registry->register(DatabasesPolicy::RESTORE, [Role::Admin], 'Restore backups (overwrites data)', 'databases');
        $registry->register(DatabasesPolicy::STORAGE, [Role::Admin], 'Manage backup storage providers and their credentials', 'databases');

        $types = $this->app->make(AlertTypes::class);
        $types->register(BackupFailed::ALERT_TYPE, 'Database backup failed', 'Databases', Severity::Critical);
        $types->register(BackupSucceeded::ALERT_TYPE, 'Database backups succeed again', 'Databases', Severity::Info);
        $types->register(RestoreFinished::ALERT_FAILED, 'Database restore failed', 'Databases', Severity::Critical);
        $types->register(RestoreFinished::ALERT_SUCCEEDED, 'Database restore finished', 'Databases', Severity::Info);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        Event::listen(ServerProvisioned::class, SyncDatabaseEngine::class);
        Event::listen(DatabaseEngineInstalled::class, [SyncDatabaseEngine::class, 'installed']);
        Event::listen(DatabaseEngineInstallFailed::class, ForgetFailedEngine::class);
        Event::listen(AgentVersionChanged::class, EnableContainerAccessOnUpgrade::class);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        // Redis / Valkey network access follows who uses an instance and how servers reach each other.
        Event::listen(ServiceLinked::class, [ConvergeKeyValueNetworkOnChange::class, 'serviceLinked']);
        Event::listen(ServiceUnlinked::class, [ConvergeKeyValueNetworkOnChange::class, 'serviceUnlinked']);
        Event::listen(SiteTargetsChanged::class, [ConvergeKeyValueNetworkOnChange::class, 'siteTargetsChanged']);
        Event::listen(PrivateNetworkChanged::class, [ConvergeKeyValueNetworkOnChange::class, 'privateNetworkChanged']);
        Event::listen(ServerProvisioned::class, [ConvergeKeyValueNetworkOnChange::class, 'serverProvisioned']);
        Event::listen(AgentFactsReported::class, [ConvergeKeyValueNetworkOnChange::class, 'agentFactsReported']);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationData::class);

        if (($invalid = (array) config('databases.container_networks_invalid', [])) !== [] && $this->app->runningInConsole()) {
            Log::warning('FALAK_DOCKER_NETWORKS: ignoring '.implode(', ', $invalid).' (IPv4 networks in CIDR form, /8–/30); containers use '.(implode(', ', (array) config('databases.container_networks', [])) ?: 'none').'.');
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new RunDueBackups)->everyMinute()->name('databases:backups')->withoutOverlapping();
        });
    }
}
