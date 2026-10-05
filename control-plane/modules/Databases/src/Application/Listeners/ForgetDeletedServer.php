<?php

namespace Falak\Databases\Application\Listeners;

use Falak\Databases\Domain\Enums\BackupStatus;
use Falak\Databases\Domain\Models\Backup;
use Falak\Databases\Domain\Models\DatabaseServer;
use Falak\Servers\Events\ServerDeleted;
use Illuminate\Contracts\Queue\ShouldQueue;

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
