<?php

namespace Falak\Databases\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Falak\Databases\Application\Actions\SetEngineVersion;
use Falak\Databases\Application\ConnectionInfo;
use Falak\Databases\Application\EngineInventory;
use Falak\Databases\Domain\Enums\Compression;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\Database;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Databases\Domain\Models\DatabaseUser;
use Falak\Databases\Domain\Models\Restore;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;

final class DatabaseServerController extends Controller
{
    use PresentsDatabases;

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
    ) {}

    /**
     * Engine servers page; JSON (engine servers only) for the canvas Create picker's Database step.
     */
    public function index(Request $request, EngineInventory $inventory): Response|JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, DatabasesPolicy::VIEW);

        $inventory->syncOrganization($organizationId);

        $servers = DatabaseServer::query()
            ->where('organization_id', $organizationId)
            ->withCount(['databases', 'users'])
            ->orderBy('server_name')
            ->get();

        if ($request->wantsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['data' => $servers->map(fn (DatabaseServer $server) => $this->presentServer($server))->values()]);
        }

        $recent = Backup::query()->with('storageProvider')->where('organization_id', $organizationId)->latest()->orderByDesc('id')->limit(10)->get();

        return Inertia::render('Databases/Index', [
            'servers' => $servers->map(fn (DatabaseServer $server) => $this->presentServer($server))->values(),
            'recentBackups' => $recent->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
            'storageProviders' => StorageProvider::query()->where('organization_id', $organizationId)->count(),
            'can' => [
                'manageStorage' => $this->access->can($request->user(), $organizationId, DatabasesPolicy::STORAGE),
            ],
        ]);
    }

    public function show(Request $request, DatabaseServer $databaseServer, ConnectionInfo $connection): Response
    {
        $this->authorize('view', $databaseServer);
        $user = $request->user();
        $organizationId = $databaseServer->organization_id;

        $databaseServer->load([
            'databases',
            'users.grants.database',
            'schedules.databases',
            'schedules.storageProvider',
        ]);

        $backups = Backup::query()->with('storageProvider')
            ->where('database_server_id', $databaseServer->id)
            ->latest()->orderByDesc('id')->limit(50)->get();

        $restores = Restore::query()->with('backup')
            ->where('database_server_id', $databaseServer->id)
            ->latest()->orderByDesc('id')->limit(20)->get();

        return Inertia::render('Databases/Show', [
            'server' => $this->presentServer($databaseServer),
            'connection' => $connection->for($databaseServer),
            'databases' => $databaseServer->databases->map(fn (Database $database) => $this->presentDatabase($database))->values(),
            'users' => $databaseServer->users->map(fn (DatabaseUser $dbUser) => $this->presentUser($dbUser))->values(),
            'schedules' => $databaseServer->schedules->map(fn (BackupSchedule $schedule) => $this->presentSchedule($schedule))->values(),
            'backups' => $backups->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
            'restores' => $restores->map(fn (Restore $restore) => $this->presentRestore($restore))->values(),
            'storageProviders' => StorageProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get(['id', 'name', 'driver', 'bucket'])
                ->map(fn (StorageProvider $provider) => ['id' => $provider->id, 'name' => $provider->name, 'driver' => $provider->driver->value, 'bucket' => $provider->bucket])->values(),
            'restoreTargets' => DatabaseServer::query()->where('organization_id', $organizationId)->orderBy('server_name')->get()
                ->filter(fn (DatabaseServer $target) => ! $target->engine->isKeyValue() && $target->engine->protocol() === $databaseServer->engine->protocol())
                ->map(fn (DatabaseServer $target) => ['id' => $target->id, 'label' => "{$target->server_name} ({$target->label()})"])->values(),
            'options' => [
                'privileges' => $databaseServer->engine->privileges(),
                'versions' => array_values((array) config("databases.versions.{$databaseServer->engine->value}", [])),
                'compressions' => array_map(fn (Compression $c) => $c->value, Compression::cases()),
                'default_charset' => $databaseServer->engine->defaultCharset(),
                'default_collation' => $databaseServer->engine->defaultCollation(),
            ],
            'can' => [
                'manage' => $this->access->can($user, $organizationId, DatabasesPolicy::MANAGE),
                'reveal' => $this->access->can($user, $organizationId, DatabasesPolicy::REVEAL),
                'restore' => $this->access->can($user, $organizationId, DatabasesPolicy::RESTORE),
                'manageStorage' => $this->access->can($user, $organizationId, DatabasesPolicy::STORAGE),
            ],
        ]);
    }

    public function update(Request $request, DatabaseServer $databaseServer, SetEngineVersion $set): RedirectResponse
    {
        $this->authorize('manage', $databaseServer);

        $data = $request->validate([
            'version' => ['nullable', 'string', Rule::in((array) config("databases.versions.{$databaseServer->engine->value}", []))],
            // Redis / Valkey instances have their own ports; the engine row's port is the stock instance's.
            'port' => [$databaseServer->engine->isKeyValue() ? 'nullable' : 'required', 'integer', 'between:1,65535'],
        ]);

        $set($databaseServer, $data['version'] ?? null, (int) ($data['port'] ?? $databaseServer->port));

        return back();
    }
}
