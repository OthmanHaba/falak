<?php

namespace Falak\Volumes\Application\Jobs;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;
use Falak\Volumes\Domain\Enums\OperationKind;
use Falak\Volumes\Domain\Models\Operation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Hourly: files and folders users downloaded from volumes are removed from storage after volumes.downloads_keep_hours,
 * and operations older than a month are forgotten.
 */
final class PruneDownloads implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(BackupStorage $storage): void
    {
        Operation::query()
            ->where('kind', OperationKind::Download)
            ->where('created_at', '<', now()->subHours((int) config('volumes.downloads_keep_hours', 24)))
            ->whereNull('meta->pruned')
            ->orderBy('id')
            ->each(function (Operation $operation) use ($storage) {
                try {
                    $storage->delete($operation->organization_id, (string) $operation->meta('storage_provider_id'), (string) $operation->meta('object_key'));
                } catch (StorageUnavailable) {
                    // A deleted provider took the object with it; a failing one is retried next hour (until a month).
                    if ($operation->created_at->gt(now()->subMonth())) {
                        return;
                    }
                }

                $operation->forceFill(['meta' => [...(array) $operation->meta, 'pruned' => true]])->save();
            });

        Operation::query()->where('created_at', '<', now()->subMonth())->whereNotNull('finished_at')->delete();
    }
}
