<?php

namespace Falak\Projects\Contracts;

/**
 * What a project service points at: a Sites site or a Databases database (opaque ULID `ref_id`).
 */
enum ServiceKind: string
{
    case Site = 'site';
    case Database = 'database';
}
