<?php

namespace Falak\Databases\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;
use Falak\Identity\Contracts\AuditLog;

/**
 * Writes and deletes a probe object with the provider's credentials (the same permissions backups and
 * pruning need).
 */
final class VerifyStorageProvider
{
    public function __construct(
        private readonly ObjectStores $stores,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(StorageProvider $provider): void
    {
        $store = $this->stores->for($provider);
        $key = $store->key('.falak-verify-'.Str::lower((string) Str::ulid()));

        try {
            $store->put($key, 'falak storage verification '.now()->toIso8601String()."\n", 'text/plain');
            $store->delete($key);
        } catch (StorageRequestFailed $e) {
            $provider->forceFill(['verified_at' => null])->save();

            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        $provider->forceFill(['verified_at' => now()])->save();
        $this->audit->record('databases.storage_provider_verified', 'storage_provider', $provider->id, ['name' => $provider->name], $provider->organization_id);
    }
}
