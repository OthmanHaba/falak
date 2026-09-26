<?php

namespace Kiln\Edge\Domain\Enums;

enum InstallStatus: string
{
    case Pending = 'pending';
    case Installed = 'installed';
    case Failed = 'failed';
    case Removing = 'removing';
}
