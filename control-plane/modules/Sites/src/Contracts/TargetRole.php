<?php

namespace Kiln\Sites\Contracts;

enum TargetRole: string
{
    /** Runs once-per-deploy steps (migrations) and the scheduler. Exactly one per site. */
    case Leader = 'leader';
    case Member = 'member';
}
