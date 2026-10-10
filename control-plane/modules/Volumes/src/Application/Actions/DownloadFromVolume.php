<?php

namespace Falak\Volumes\Application\Actions;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Volumes\Application\AgentCommands;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Enums\OperationStatus;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A file of a volume (as is) or a folder (tar.zst) for the user: the agent uploads it to a presigned PUT URL in one of
 * the organization's storage providers, capped in size; the user then gets a short-lived link ({@see link()}). Both are
 * audited, and the objects are removed after a day (PruneDownloads).
 */
final class DownloadFromVolume
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly BrowseVolume $browse,
        private readonly BackupStorage $storage,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Volume $volume, string $path, string $storageProviderId, ?string $actorId = null): Operation
    {
        $serverId = $this->browse->serverOf($volume);
        $path = BrowseVolume::relative($path);

        if ($this->storage->find($volume->organization_id, $storageProviderId) === null) {
            throw ValidationException::withMessages(['storage_provider_id' => 'Downloads go through backup storage: add a storage provider first.']);
        }

        $operation = new Operation;
        $operation->id = strtolower((string) Str::ulid());
        $key = $this->storage->key($volume->organization_id, $storageProviderId, 'volumes', 'downloads', now()->utc()->format('Ymd'), $operation->id);
        $max = (int) config('volumes.download_max_bytes');

        $operation->forceFill([
            'organization_id' => $volume->organization_id,
            'volume_id' => $volume->id,
            'kind' => OperationKind::Download,
            'status' => OperationStatus::Running,
            'meta' => ['path' => $path, 'storage_provider_id' => strtolower($storageProviderId), 'object_key' => $key],
            'requested_by' => $actorId,
        ])->save();

        try {
            $handle = $this->commands->dispatch($serverId, 'volume.download', [
                'volume' => $volume->ref(),
                'path' => $path,
                'destination' => ['kind' => 'presigned_url', 'url' => $this->storage->presignPut($volume->organization_id, $storageProviderId, $key)],
                'max_bytes' => $max,
            ], "volume.download:{$operation->id}", 'path');
        } catch (ValidationException|StorageUnavailable $e) {
            $operation->delete();

            throw $e instanceof ValidationException ? $e : ValidationException::withMessages(['storage_provider_id' => $e->getMessage()]);
        }

        $operation->forceFill(['command_id' => $handle->id])->save();

        $this->audit->record('volumes.download_requested', 'volume', $volume->id, ['name' => $volume->name, 'path' => $path], $volume->organization_id);

        return $operation;
    }

    /**
     * A short-lived link to a finished download.
     *
     * @throws ValidationException
     */
    public function link(Operation $operation): string
    {
        $provider = (string) $operation->meta('storage_provider_id');
        $key = (string) $operation->meta('object_key');

        if ($operation->kind !== OperationKind::Download || $operation->status !== OperationStatus::Succeeded || $operation->meta('pruned') === true) {
            throw ValidationException::withMessages(['download' => 'The download is not ready (or expired).']);
        }

        try {
            $url = $this->storage->presignGet($operation->organization_id, $provider, $key, (int) config('volumes.download_link_ttl', 300));
        } catch (StorageUnavailable $e) {
            throw ValidationException::withMessages(['download' => $e->getMessage()]);
        }

        $this->audit->record('volumes.downloaded', 'volume', (string) $operation->volume_id, ['path' => $operation->meta('path'), 'operation_id' => $operation->id], $operation->organization_id);

        return $url;
    }
}
