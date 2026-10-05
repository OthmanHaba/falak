<?php

namespace Falak\Deployments\Domain\Enums;

enum TargetStatus: string
{
    case Pending = 'pending';
    case Deploying = 'deploying';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case Skipped = 'skipped';
}
