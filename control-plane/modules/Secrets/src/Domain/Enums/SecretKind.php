<?php

namespace Falak\Secrets\Domain\Enums;

enum SecretKind: string
{
    /** The value is stored here, sealed. */
    case Managed = 'managed';

    /** A reference to a value kept by an external provider (`vault://…`, `aws-sm://…`), resolved at deploy time. */
    case Linked = 'linked';
}
