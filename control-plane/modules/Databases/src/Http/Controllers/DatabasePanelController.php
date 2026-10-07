<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\UpdateInstance;
use Falak\Databases\Application\ConnectionInfo;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One database as a canvas service (UI_DESIGN §5.4): JSON for the database panel's tabs, with its container
 * (instance); a browser visit opens the panel (or the container's page while the database is not placed in a project).
 */
final class DatabasePanelController extends Controller
{
    use PresentsDatabases;

    public function __construct(private readonly OrganizationAccess $access) {}

    public function show(Request $request, Database $database, ConnectionInfo $connection, ProjectDirectory $projects): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $database);

        if (! $request->wantsJson() || $request->header('X-Inertia') !== null) {
            return redirect($projects->serviceUrl(ServiceKind::Database, $database->id) ?? "/databases/instances/{$database->database_instance_id}");
        }

        $instance = $database->instance;
        $user = $request->user();
        $organizationId = $database->organization_id;

        $users = DatabaseUser::query()->with('grants.database')
            ->where('database_instance_id', $instance->id)
            ->whereHas('grants', fn ($q) => $q->where('database_id', $database->id))
            ->orderBy('created_at')->orderBy('id')->get();

        $schedules = BackupSchedule::query()->with(['databases', 'storageProvider'])
            ->where('database_instance_id', $instance->id)
            ->whereHas('databases', fn ($q) => $q->whereKey($database->id))
            ->orderBy('name')->get();

        $backups = Backup::query()->with('storageProvider')
            ->where('database_id', $database->id)
            ->latest()->orderByDesc('id')->limit(50)->get();

        $restores = Restore::query()->with('backup')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('database_instance_id', $instance->id)->where('database_name', $database->name))
                ->orWhereIn('backup_id', $backups->pluck('id')))
            ->latest()->orderByDesc('id')->limit(20)->get();

        return response()->json(['data' => [
            'database' => $this->presentDatabase($database),
            'instance' => $this->presentInstance($instance),
            'connection' => $connection->for($instance),
            'users' => $users->map(fn (DatabaseUser $dbUser) => $this->presentUser($dbUser))->values(),
            'schedules' => $schedules->map(fn (BackupSchedule $schedule) => $this->presentSchedule($schedule))->values(),
            'backups' => $backups->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
            'restores' => $restores->map(fn (Restore $restore) => $this->presentRestore($restore))->values(),
            'storage_providers' => StorageProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'driver', 'bucket'])
                ->map(fn (StorageProvider $provider) => ['id' => $provider->id, 'name' => $provider->name, 'driver' => $provider->driver->value, 'bucket' => $provider->bucket])->values(),
            'restore_targets' => $this->restoreTargets($instance),
            'options' => [
                'privileges' => $instance->engine->privileges(),
                'versions' => array_values(array_filter($instance->engine->versions(), fn (string $version) => version_compare($version, $instance->version, '>='))),
                'compressions' => array_map(fn (Compression $c) => $c->value, Compression::cases()),
                'evictions' => $instance->engine->isKeyValue() ? UpdateInstance::EVICTIONS : [],
                'persistences' => $instance->engine->isKeyValue() ? UpdateInstance::PERSISTENCES : [],
                'min_memory_mb' => intdiv($instance->engine->minMemory(), 1024 ** 2),
                'upgradable' => $instance->status === InstanceStatus::Active,
            ],
            'can' => [
                'manage' => $this->access->can($user, $organizationId, DatabasesPolicy::MANAGE),
                'reveal' => $this->access->can($user, $organizationId, DatabasesPolicy::REVEAL),
                'restore' => $this->access->can($user, $organizationId, DatabasesPolicy::RESTORE),
                'manage_storage' => $this->access->can($user, $organizationId, DatabasesPolicy::STORAGE),
            ],
        ]]);
    }
}
