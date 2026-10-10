<?php

namespace Falak\Databases\Http\Controllers;

use Falak\Databases\Application\Actions\DeleteBackup;
use Falak\Databases\Application\Actions\RestoreBackup;
use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Domain\Policies\DatabasesPolicy;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Kernel\Security\BackupKeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class BackupController extends Controller
{
    use PresentsDatabases;

    public function index(Request $request, CurrentOrganization $organization, OrganizationAccess $access): Response
    {
        $organizationId = $organization->requireId();
        $access->authorize($request->user(), $organizationId, DatabasesPolicy::VIEW);

        $filters = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', array_map(fn (BackupStatus $s) => $s->value, BackupStatus::cases()))],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $backups = Backup::query()
            ->with('storageProvider')
            ->where('organization_id', $organizationId)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('database_name', 'like', "%{$search}%")->orWhere('server_name', 'like', "%{$search}%")))
            ->latest()
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Databases/Backups', [
            'backups' => [
                'data' => collect($backups->items())->map(fn (Backup $backup) => $this->presentBackup($backup))->values(),
                'current_page' => $backups->currentPage(),
                'last_page' => $backups->lastPage(),
                'total' => $backups->total(),
            ],
            'filters' => array_filter($filters),
            'can' => [
                'manage' => $access->can($request->user(), $organizationId, DatabasesPolicy::MANAGE),
                'restore' => $access->can($request->user(), $organizationId, DatabasesPolicy::RESTORE),
            ],
        ]);
    }

    public function restore(Request $request, Backup $backup, RestoreBackup $restore): RedirectResponse
    {
        $this->authorize('restore', $backup);

        $data = $request->validate([
            'database_instance_id' => ['required', 'string'],
            'database' => ['required', 'string', 'max:63'],
            'confirm' => ['required', 'string', 'same:database'],
            // Customer-held keys only: used for this restore, never stored.
            'identity' => ['nullable', 'string', 'max:200'],
        ], ['confirm.same' => 'Type the target database name to confirm.']);

        $target = DatabaseInstance::query()->where('organization_id', $backup->organization_id)->findOrFail($data['database_instance_id']);

        $restore($backup, $target, $data['database'], $request->user()?->getAuthIdentifier(), $data['identity'] ?? null);

        return back();
    }

    /**
     * POST /databases/backups/{backup}/key: the backup's data key as a falak-restore key file, for restoring it without
     * Falak. Holding it opens the backup, so it takes the restore permission and a recent re-authentication, and is
     * audited. Customer-held keys were never here.
     */
    public function exportKey(Backup $backup, BackupKeys $keys, AuditLog $audit): JsonResponse
    {
        $this->authorize('restore', $backup);

        if ($backup->isCustomerHeld() || $backup->wrapped_key === null || $backup->encryption_mode !== BackupKeys::CP) {
            throw ValidationException::withMessages(['backup' => $backup->isCustomerHeld()
                ? 'This backup\'s key is customer-held: Falak never had it. Use your age identity with falak-restore.'
                : 'This backup has no key to export.']);
        }

        $key = $keys->unwrap($backup->wrapped_key, $backup->organization_id, $backup->id);

        try {
            $file = BackupKeys::keyFile($backup->id, $key);
        } finally {
            sodium_memzero($key);
        }

        $audit->record('databases.backup_key_exported', 'backup', $backup->id, [
            'database' => $backup->database_name,
            'server_id' => $backup->server_id,
        ], $backup->organization_id);

        return response()->json(['key_id' => $backup->id, 'filename' => "falak-backup-{$backup->id}.key", 'content' => $file])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * GET /databases/backups/{backup}/download: a short-lived presigned GET URL of the object (redirect, or `{url}` as
     * JSON). The file holds all the data (SQL dump or RDB snapshot), so it takes the restore permission; audited.
     */
    public function download(Request $request, Backup $backup, ObjectStores $stores, AuditLog $audit): RedirectResponse|JsonResponse
    {
        $this->authorize('restore', $backup);

        if (! $backup->isRestorable() || ! $backup->storageProvider) {
            throw ValidationException::withMessages(['backup' => 'Only successful backups whose storage provider still exists can be downloaded.']);
        }

        $url = $stores->for($backup->storageProvider)->presignGet($backup->object_key, (int) config('databases.download_link_ttl', 300));

        $audit->record('databases.backup_downloaded', 'backup', $backup->id, [
            'database' => $backup->database_name,
            'server_id' => $backup->server_id,
        ], $backup->organization_id);

        return $request->wantsJson() && $request->header('X-Inertia') === null
            ? response()->json(['url' => $url])
            : redirect()->away($url);
    }

    public function destroy(Backup $backup, DeleteBackup $delete): RedirectResponse
    {
        $this->authorize('manage', $backup);

        $delete($backup);

        return back();
    }
}
