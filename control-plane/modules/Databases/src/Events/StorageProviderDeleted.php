<?php

namespace Falak\Databases\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A backup storage provider was removed (its objects stay in the bucket). Other modules' schedules that used it stop.
 */
final class StorageProviderDeleted
{
    use Dispatchable;

    public function __construct(
        public string $providerId,
        public string $organizationId,
        public string $name,
    ) {}
}
