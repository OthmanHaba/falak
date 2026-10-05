<?php

namespace Falak\Network\Domain\Enums;

enum RuleAction: string
{
    case Allow = 'allow';
    case Deny = 'deny';

    /** nftables verdict in net.firewall.apply. */
    public function verdict(): string
    {
        return $this === self::Allow ? 'accept' : 'drop';
    }
}
