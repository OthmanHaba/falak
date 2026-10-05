<?php

namespace Falak\Sites\Contracts;

/**
 * Site preparation on a server: unix user (+ PHP-FPM pool). pending → provisioning → ready | failed.
 */
enum TargetStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Ready = 'ready';
    case Failed = 'failed';
    case Removing = 'removing';
}
