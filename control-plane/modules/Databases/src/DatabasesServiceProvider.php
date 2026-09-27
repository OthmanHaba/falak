<?php

namespace Kiln\Databases;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Contracts\Severity;
use Kiln\Databases\Application\Jobs\RunDueBackups;
use Kiln\Databases\Application\Listeners\DeleteOrganizationData;
use Kiln\Databases\Application\Listeners\ForgetDeletedServer;
use Kiln\Databases\Application\Listeners\HandleCommandOutcome;
use Kiln\Databases\Application\Listeners\SyncDatabaseEngine;
use Kiln\Databases\Contracts\DatabaseConnections;
use Kiln\Databases\Contracts\DatabaseDirectory;
use Kiln\Databases\Contracts\DatabaseProvisioner;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Domain\Models\Restore;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Databases\Domain\Policies\DatabasesPolicy;
use Kiln\Databases\Events\BackupFailed;
use Kiln\Databases\Events\BackupSucceeded;
use Kiln\Databases\Events\RestoreFinished;
use Kiln\Databases\Infrastructure\ActionDatabaseProvisioner;
use Kiln\Databases\Infrastructure\EloquentDatabaseConnections;
use Kiln\Databases\Infrastructure\EloquentDatabaseDirectory;
use Kiln\Databases\Infrastructure\ObjectStorage\EndpointGuard;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;
use Kiln\Identity\Contracts\PermissionRegistry;
use Kiln\Identity\Contracts\Role;
use Kiln\Identity\Events\OrganizationDeleted;
use Kiln\Kernel\Support\ModuleServiceProvider;
use Kiln\Servers\Events\ServerDeleted;
use Kiln\Servers\Events\ServerProvisioned;

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
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/databases.php', 'databases');

        $this->app->bind(EndpointGuard::class, fn () => new EndpointGuard((bool) config('databases.allow_private_endpoints', false)));
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
        Event::listen(ServerDeleted::class, ForgetDeletedServer::class);
        Event::listen(OrganizationDeleted::class, DeleteOrganizationData::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new RunDueBackups)->everyMinute()->name('databases:backups')->withoutOverlapping();
        });
    }
}
