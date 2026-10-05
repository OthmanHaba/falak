<?php

namespace Falak\Identity\Application\Listeners;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Schema;
use Falak\Identity\Application\Actions\SyncPermissions;

/**
 * Keeps roles/permissions converged with the registry after every `migrate` run.
 */
final class SyncPermissionsAfterMigrations
{
    public function __construct(private readonly SyncPermissions $sync) {}

    public function handle(MigrationsEnded $event): void
    {
        if ($event->method !== 'up' || ! Schema::hasTable('identity_roles')) {
            return;
        }

        ($this->sync)();
    }
}
