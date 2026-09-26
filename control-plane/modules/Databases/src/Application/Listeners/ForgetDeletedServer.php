<?php

namespace Kiln\Databases\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kiln\Databases\Domain\Enums\BackupStatus;
use Kiln\Databases\Domain\Models\Backup;
use Kiln\Databases\Domain\Models\DatabaseServer;
use Kiln\Servers\Events\ServerDeleted;

/**
 * The server is gone: drop its engine, databases, users and schedules. Backup history (and the objects
 * in storage) is kept so dumps can still be restored onto another server.
 */
final class ForgetDeletedServer implements ShouldQueue
{
    public function handle(ServerDeleted $event): void
    {
        Backup::query()->where('server_id', $event->serverId)->whereIn('status', [BackupStatus::Pending, BackupStatus::Running])
            ->update(['status' => BackupStatus::Failed, 'error' => 'The server was deleted.', 'finished_at' => now()]);

        DatabaseServer::query()->where('server_id', $event->serverId)->get()->each->delete();
    }
}
