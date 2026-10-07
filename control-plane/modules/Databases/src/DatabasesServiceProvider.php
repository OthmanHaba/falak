<?php

namespace Falak\Databases;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;
use Falak\Databases\Application\Jobs\RemoveRetiredInstances;
use Falak\Databases\Application\Jobs\RunDueBackups;
use Falak\Databases\Application\Listeners\ConvergeInstanceNetwork;
use Falak\Databases\Application\Listeners\DeleteOrganizationData;
use Falak\Databases\Application\Listeners\ForgetDeletedServer;
use Falak\Databases\Application\Listeners\HandleCommandOutcome;
use Falak\Databases\Application\Listeners\RecordInstanceHealth;
use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\DatabaseConnections;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Databases\Events\BackupFailed;
use Falak\Databases\Events\BackupSucceeded;
use Falak\Databases\Events\RestoreFinished;
use Falak\Databases\Infrastructure\ActionDatabaseProvisioner;
use Falak\Databases\Infrastructure\EloquentDatabaseConnections;
use Falak\Databases\Infrastructure\EloquentDatabaseDirectory;
use Falak\Databases\Infrastructure\ObjectStorageBackupStorage;
use Falak\Fleet\Events\AgentDatabasesReported;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Network\Events\PrivateNetworkChanged;
use Falak\Projects\Events\ServiceLinked;
use Falak\Projects\Events\ServiceUnlinked;
use Falak\Servers\Events\ServerDeleted;
use Falak\Sites\Events\SiteTargetsChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

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
        BackupStorage::class => ObjectStorageBackupStorage::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/databases.php', 'databases');
    }

    protected function bootModule(): void
    {
        foreach ([DatabaseInstance::class, Database::class, DatabaseUser::class, StorageProvider::class, BackupSchedule::class, Backup::class, Restore::class] as $model) {
            Gate::policy($model, DatabasesPolicy::class);
        }

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(DatabasesPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View database servers, databases, users and backups', 'databases');
        $registry->register(DatabasesPolicy::MANAGE, [Role::Admin, Role::Developer], 'Create, change, upgrade and delete database servers, databases and users, manage backup schedules and run backups', 'databases');
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
        Event::listen(AgentDatabasesReported::class, RecordInstanceHealth::class);
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        // The private addresses a container is published on follow who uses it and how servers reach each other.
        Event::listen(ServiceLinked::class, [ConvergeInstanceNetwork::class, 'serviceLinked']);
        Event::listen(ServiceUnlinked::class, [ConvergeInstanceNetwork::class, 'serviceUnlinked']);
        Event::listen(SiteTargetsChanged::class, [ConvergeInstanceNetwork::class, 'siteTargetsChanged']);
        Event::listen(PrivateNetworkChanged::class, [ConvergeInstanceNetwork::class, 'privateNetworkChanged']);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationData::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new RunDueBackups)->everyMinute()->name('databases:backups')->withoutOverlapping();
            $schedule->job(new RemoveRetiredInstances)->everyTenMinutes()->name('databases:retired')->withoutOverlapping();
        });
    }
}
