<?php

namespace Falak\Databases\Domain\Enums;

/**
 * Backups are always zstd-compressed, then encrypted (FKB1, docs/BACKUPS.md).
 */
enum Compression: string
{
    case Zstd = 'zstd';

    /**
     * The object key's extension: a SQL dump, or a Redis / Valkey RDB snapshot, compressed and encrypted.
     */
    public function extension(?Engine $engine = null): string
    {
        return ($engine?->isKeyValue() ? '.rdb' : '.sql').'.zst.fkb';
    }
}
