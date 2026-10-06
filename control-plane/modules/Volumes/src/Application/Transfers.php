<?php

namespace Falak\Volumes\Application;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;
use Falak\Volumes\Application\Actions\PruneVolumeBackups;
use Falak\Volumes\Domain\Enums\BackupStatus;
use Falak\Volumes\Domain\Enums\VolumeStatus;
use Falak\Volumes\Domain\Models\Attachment;
use Falak\Volumes\Domain\Models\Operation;
use Falak\Volumes\Domain\Models\Volume;
use Falak\Volumes\Domain\Models\VolumeBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Data moving between volumes through object storage: a backup restored into a new volume (restores, and the second
 * half of clones and moves to another server), attachments handed from one volume to another.
 */
final class Transfers
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly BackupStorage $storage,
        private readonly Redeployer $redeployer,
        private readonly PruneVolumeBackups $prune,
    ) {}

    /**
     * volume.restore of $backup into $target (saved, pending): the agent creates it, checks it is empty, downloads the
     * snapshot from a presigned GET URL and verifies its sha256 before unpacking.
     *
     * @throws ValidationException when $background is false and the agent is not connected
     */
    public function restore(Operation $operation, VolumeBackup $backup, Volume $target, bool $background = false): bool
    {
        try {
            $url = $this->storage->presignGet($backup->organization_id, (string) $backup->storage_provider_id, $backup->object_key);
        } catch (StorageUnavailable $e) {
            $this->failed($operation, $target, $e->getMessage());

            return false;
        }

        $payload = array_filter([
            ...AgentCommands::createPayload($target),
            'source' => ['kind' => 'url', 'url' => $url],
            'sha256' => $backup->sha256,
            'uncompressed_bytes' => $backup->uncompressed_bytes ?: null,
        ], fn ($v) => $v !== null);
        $key = "volume.restore:{$operation->id}";

        $handle = $background
            ? $this->commands->tryDispatch((string) $target->server_id, 'volume.restore', $payload, $key)
            : $this->commands->dispatch((string) $target->server_id, 'volume.restore', $payload, $key, 'server_id');

        if ($handle === null) {
            $this->failed($operation, $target, AgentCommands::NOT_CONNECTED);

            return false;
        }

        $target->forceFill(['command_id' => $handle->id])->save();
        $operation->forceFill(['step' => 'restore', 'command_id' => $handle->id, 'meta' => [...(array) $operation->meta, 'backup_id' => $backup->id]])->save();

        return true;
    }

    /**
     * Point every attachment of $from at $to (a restore swapped in, a move) and redeploy the sites concerned.
     *
     * @return list<string> the sites redeployed
     */
    public function handOver(Volume $from, Volume $to, ?string $actorId, string $reason): array
    {
        $siteIds = DB::transaction(function () use ($from, $to) {
            $attachments = Attachment::query()->where('volume_id', $from->id)->lockForUpdate()->get();
            Attachment::query()->whereIn('id', $attachments->modelKeys())->update(['volume_id' => $to->id]);

            return $attachments->map(fn (Attachment $attachment) => $attachment->siteId())->filter()->values()->all();
        });

        return $this->redeployer->redeploy($siteIds, $actorId, $reason);
    }

    /**
     * Remove a backup that only carried data between servers (moves, clones) once it was restored.
     */
    public function discard(?VolumeBackup $backup): void
    {
        if ($backup === null || $backup->status !== BackupStatus::Succeeded || in_array($backup->trigger, ['manual', 'scheduled'], true)) {
            return;
        }

        $this->prune->prune($backup);
    }

    public function failed(Operation $operation, ?Volume $target, string $error): void
    {
        $operation->fail($error);

        if ($target !== null && $target->exists && $target->status === VolumeStatus::Pending) {
            $target->forceFill(['status' => VolumeStatus::Failed, 'status_message' => mb_substr($error, 0, 1000)])->save();
        }
    }
}
