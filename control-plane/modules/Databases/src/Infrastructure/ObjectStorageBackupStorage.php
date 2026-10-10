<?php

namespace Falak\Databases\Infrastructure;

use Falak\Databases\Contracts\BackupStorage;
use Falak\Databases\Contracts\Data\StorageProviderData;
use Falak\Databases\Contracts\Exceptions\StorageUnavailable;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStore;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStores;
use Falak\Databases\Infrastructure\ObjectStorage\StorageRequestFailed;

final class ObjectStorageBackupStorage implements BackupStorage
{
    public function __construct(private readonly ObjectStores $stores) {}

    public function providers(string $organizationId): array
    {
        return StorageProvider::query()->where('organization_id', $organizationId)->orderBy('name')->get()
            ->map(fn (StorageProvider $provider) => self::data($provider))->values()->all();
    }

    public function find(string $organizationId, string $providerId): ?StorageProviderData
    {
        $provider = $this->provider($organizationId, $providerId);

        return $provider !== null ? self::data($provider) : null;
    }

    public function key(string $organizationId, string $providerId, string ...$segments): string
    {
        return $this->store($organizationId, $providerId)->key(...$segments);
    }

    public function presignPut(string $organizationId, string $providerId, string $key, ?int $ttlSeconds = null): string
    {
        return $this->store($organizationId, $providerId)->presignPut($key, $ttlSeconds ?? (int) config('databases.upload_url_ttl', 43200));
    }

    public function presignGet(string $organizationId, string $providerId, string $key, ?int $ttlSeconds = null): string
    {
        return $this->store($organizationId, $providerId)->presignGet($key, $ttlSeconds ?? (int) config('databases.download_url_ttl', 21600));
    }

    public function delete(string $organizationId, string $providerId, string $key): void
    {
        try {
            $this->store($organizationId, $providerId)->delete($key);
        } catch (StorageRequestFailed $e) {
            throw new StorageUnavailable($e->getMessage(), 0, $e);
        }
    }

    private function store(string $organizationId, string $providerId): ObjectStore
    {
        return $this->stores->for($this->provider($organizationId, $providerId) ?? throw StorageUnavailable::unknown($providerId));
    }

    private function provider(string $organizationId, string $providerId): ?StorageProvider
    {
        return StorageProvider::query()->where('organization_id', $organizationId)->find(strtolower($providerId));
    }

    private static function data(StorageProvider $provider): StorageProviderData
    {
        return new StorageProviderData($provider->id, $provider->organization_id, $provider->name, $provider->driver->value, $provider->bucket);
    }
}
