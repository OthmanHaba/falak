<?php

namespace Falak\Databases\Contracts\Data;

/**
 * A backup storage provider, without its credentials.
 */
final readonly class StorageProviderData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $name,
        public string $driver,
        public string $bucket,
    ) {}

    /**
     * @return array{id: string, name: string, driver: string, bucket: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'driver' => $this->driver, 'bucket' => $this->bucket];
    }
}
