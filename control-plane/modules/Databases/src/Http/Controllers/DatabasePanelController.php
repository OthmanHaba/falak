<?php

namespace Kiln\Databases\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Kiln\Databases\Application\ConnectionInfo;
use Kiln\Databases\Application\KeyValue\KeyValueSettings;
use Kiln\Databases\Application\KeyValue\UpdateKeyValueSettings;
use Kiln\Databases\Domain\Enums\Compression;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\BackupSchedule;
use Kiln\Databases\Domain\Models\Database;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Databases\Domain\Models\DatabaseUser;
use Kiln\Databases\Domain\Models\Restore;
use Kiln\Databases\Domain\Models\StorageProvider;
use Kiln\Databases\Domain\Policies\DatabasesPolicy;
use Kiln\Identity\Contracts\OrganizationAccess;
use Kiln\Kernel\Http\Controller;
use Kiln\Projects\Contracts\ProjectDirectory;
use Kiln\Projects\Contracts\ServiceKind;

/**
 * One database as a canvas service (UI_DESIGN §5.4): JSON for the database panel's tabs; a browser visit opens
 * the panel (or the engine page while the database is not placed in a project).
 */
final class DatabasePanelController extends Controller
{
    use PresentsDatabases;

    public function __construct(private readonly OrganizationAccess $access) {}

    public function show(Request $request, Database $database, ConnectionInfo $connection, ProjectDirectory $projects, KeyValueSettings $settings): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $database);

        if (! $request->wantsJson() || $request->header('X-Inertia') !== null) {
            return redirect($projects->serviceUrl(ServiceKind::Database, $database->id) ?? "/databases/servers/{$database->database_server_id}");
        }

        $server = DatabaseServer::query()->findOrFail($database->database_server_id);
        $user = $request->user();
        $organizationId = $database->organization_id;

        $users = DatabaseUser::query()->with('grants.database')
            ->where('database_server_id', $server->id)
            ->whereHas('grants', fn ($q) => $q->where('database_id', $database->id))
            ->orderBy('created_at')->orderBy('id')->get();

        $schedules = BackupSchedule::query()->with(['databases', 'storageProvider'])
            ->where('database_server_id', $server->id)
            ->whereHas('databases', fn ($q) => $q->whereKey($database->id))
            ->orderBy('name')->get();

        $backups = Backup::query()->with('storageProvider')
            ->where('database_id', $database->id)
            ->latest()->orderByDesc('id')->limit(50)->get();

        $restores = Restore::query()->with('backup')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('database_server_id', $server->id)->where('database_name', $database->name))
                ->orWhereIn('backup_id', $backups->pluck('id')))
            ->latest()->orderByDesc('id')->limit(20)->get();

        return response()->json(['data' => [
            'database' => $this->presentDatabase($database),
            'server' => $this->presentServer($server),
            'connection' => $connection->for($server, $database),
            'users' => $users->map(fn (DatabaseUser $dbUser) => $this->presentUser($dbUser))->values(),
            'schedules' => $schedules->map(fn (BackupSchedule $schedule) => $this->presentSchedule($schedule))->values(),
            'backups' => $backups->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
            'restores' => $restores->map(fn (Restore $restore) => $this->presentRestore($restore))->values(),
            'storage_providers' => StorageProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'driver', 'bucket'])
                ->map(fn (StorageProvider $provider) => ['id' => $provider->id, 'name' => $provider->name, 'driver' => $provider->driver->value, 'bucket' => $provider->bucket])->values(),
            'restore_targets' => DatabaseServer::query()->where('organization_id', $organizationId)->orderBy('server_name')->get()
                ->filter(fn (DatabaseServer $target) => ! $target->engine->isKeyValue() && $target->engine->protocol() === $server->engine->protocol())
                ->map(fn (DatabaseServer $target) => ['id' => $target->id, 'label' => "{$target->server_name} ({$target->label()})"])->values(),
            'options' => [
                'privileges' => $server->engine->privileges(),
                'versions' => array_values((array) config("databases.versions.{$server->engine->value}", [])),
                'compressions' => array_map(fn (Compression $c) => $c->value, Compression::cases()),
                'evictions' => $server->engine->isKeyValue() ? array_values((array) config('databases.key_value.evictions', [])) : [],
                'persistences' => $server->engine->isKeyValue() ? array_values((array) config('databases.key_value.persistences', [])) : [],
                'max_memory_mb' => $server->engine->isKeyValue() ? $settings->maxMemoryMb($server->server_id) : null,
            ],
            'can' => [
                'manage' => $this->access->can($user, $organizationId, DatabasesPolicy::MANAGE),
                'reveal' => $this->access->can($user, $organizationId, DatabasesPolicy::REVEAL),
                'restore' => $this->access->can($user, $organizationId, DatabasesPolicy::RESTORE),
                'manage_storage' => $this->access->can($user, $organizationId, DatabasesPolicy::STORAGE),
            ],
        ]]);
    }

    /**
     * PUT /databases/databases/{database}/settings {maxmemory_mb?, eviction?, persistence?} — Redis / Valkey instances.
     */
    public function settings(Request $request, Database $database, UpdateKeyValueSettings $update): RedirectResponse|JsonResponse
    {
        $this->authorize('manage', $database);

        $data = $request->validate([
            'maxmemory_mb' => ['nullable', 'integer', 'min:16', 'max:1048576'],
            'eviction' => ['nullable', 'string', 'max:32'],
            'persistence' => ['nullable', 'string', 'max:8'],
        ]);

        $database = $update($database, $data);

        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['data' => $this->presentDatabase($database)])
            : back();
    }
}
