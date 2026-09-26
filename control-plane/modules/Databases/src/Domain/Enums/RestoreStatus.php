<?php

namespace Kiln\Databases\Domain\Enums;

enum RestoreStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
