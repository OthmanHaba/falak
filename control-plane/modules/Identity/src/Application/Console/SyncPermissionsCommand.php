<?php

namespace Falak\Identity\Application\Console;

use Falak\Identity\Application\Actions\SyncPermissions;
use Illuminate\Console\Command;

final class SyncPermissionsCommand extends Command
{
    protected $signature = 'identity:permissions:sync';

    protected $description = 'Sync roles and permissions with the modules\' permission registry';

    public function handle(SyncPermissions $sync): int
    {
        $result = $sync();

        $this->components->info("Synced {$result['permissions']} permissions across {$result['roles']} roles.");

        return self::SUCCESS;
    }
}
