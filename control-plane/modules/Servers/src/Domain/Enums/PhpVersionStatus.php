<?php

namespace Falak\Servers\Domain\Enums;

enum PhpVersionStatus: string
{
    case Installing = 'installing';
    case Installed = 'installed';
    case Failed = 'failed';
    case Removing = 'removing';
}
