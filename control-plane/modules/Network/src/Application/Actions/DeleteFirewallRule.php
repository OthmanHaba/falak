<?php

namespace Falak\Network\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Application\ApplyFirewall;
use Falak\Network\Domain\Models\FirewallRule;

final class DeleteFirewallRule
{
    public function __construct(
        private readonly ApplyFirewall $apply,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(FirewallRule $rule): void
    {
        $rule->delete();

        $this->audit->record('network.firewall_rule_deleted', 'server', $rule->server_id, [
            'rule_id' => $rule->id,
            'name' => $rule->name,
            'port' => $rule->port,
            'source' => $rule->source,
        ], $rule->organization_id);

        ($this->apply)($rule->server_id);
    }
}
