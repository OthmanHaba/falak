<?php

namespace Falak\Databases\Domain\Enums;

enum Compression: string
{
    case Gzip = 'gzip';
    case None = 'none';

    public function extension(): string
    {
        return $this === self::Gzip ? '.sql.gz' : '.sql';
    }
}
