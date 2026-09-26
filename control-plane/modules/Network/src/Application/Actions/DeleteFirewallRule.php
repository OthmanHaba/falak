<?php

namespace Kiln\Network\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Network\Application\ApplyFirewall;
use Kiln\Network\Domain\Models\FirewallRule;

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
