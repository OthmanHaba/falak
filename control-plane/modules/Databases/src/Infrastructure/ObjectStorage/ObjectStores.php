<?php

namespace Falak\Databases\Infrastructure\ObjectStorage;

use Illuminate\Http\Client\Factory as HttpFactory;
use Falak\Databases\Domain\Models\StorageProvider;

final class ObjectStores
{
    public function __construct(private readonly HttpFactory $http, private readonly EndpointGuard $guard) {}

    public function for(StorageProvider $provider): ObjectStore
    {
        return new ObjectStore($provider, $this->http, (int) config('databases.storage_timeout', 30), $this->guard);
    }
}
