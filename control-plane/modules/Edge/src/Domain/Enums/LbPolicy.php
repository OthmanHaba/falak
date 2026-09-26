<?php

namespace Kiln\Edge\Domain\Enums;

enum LbPolicy: string
{
    case RoundRobin = 'round_robin';
    case LeastConn = 'least_conn';
    case First = 'first';
    case IpHash = 'ip_hash';

    public function label(): string
    {
        return match ($this) {
            self::RoundRobin => 'Round robin',
            self::LeastConn => 'Least connections',
            self::First => 'First available (failover)',
            self::IpHash => 'IP hash (sticky)',
        };
    }
}
