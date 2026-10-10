<?php

namespace Falak\Security\Domain\Enums;

enum AuditStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
}
