<?php

namespace Falak\Databases\Contracts\Exceptions;

use RuntimeException;

/**
 * A backup storage provider is unknown (deleted, or of another organization) or did not answer a request.
 */
final class StorageUnavailable extends RuntimeException
{
    public static function unknown(string $providerId): self
    {
        return new self("Storage provider {$providerId} does not exist.");
    }
}
