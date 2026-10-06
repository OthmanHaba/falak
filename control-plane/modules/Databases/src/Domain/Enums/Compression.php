<?php

namespace Falak\Databases\Domain\Enums;

enum Compression: string
{
    case Gzip = 'gzip';
    case None = 'none';

    /**
     * The object key's extension: a SQL dump, or a Redis / Valkey RDB snapshot.
     */
    public function extension(?Engine $engine = null): string
    {
        $base = $engine?->isKeyValue() ? '.rdb' : '.sql';

        return $this === self::Gzip ? "{$base}.gz" : $base;
    }
}
