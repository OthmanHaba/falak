<?php

namespace Falak\Network\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Network\Application\ApplyFirewall;
use Falak\Network\Domain\Enums\RuleAction;
use Falak\Network\Domain\Enums\RuleProtocol;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Servers\Contracts\Data\ServerData;

/**
 * Create or update a rule, then converge the server's firewall.
 */
final class SaveFirewallRule
{
    public function __construct(
        private readonly EnsureDefaultFirewallRules $defaults,
        private readonly ApplyFirewall $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name: string, action: string, protocol: string, port?: ?string, source?: ?string}  $data  validated
     */
    public function __invoke(ServerData $server, array $data, ?FirewallRule $rule = null): FirewallRule
    {
        ($this->defaults)($server);

        $attributes = [
            'name' => $data['name'],
            'action' => RuleAction::from($data['action']),
            'protocol' => RuleProtocol::from($data['protocol']),
            'port' => ($data['port'] ?? null) ?: null,
            'source' => ($data['source'] ?? null) ?: null,
        ];

        if ($rule === null) {
            $rule = FirewallRule::query()->create([
                ...$attributes,
                'organization_id' => $server->organizationId,
                'server_id' => $server->id,
                'position' => ((int) FirewallRule::query()->where('server_id', $server->id)->max('position')) + 1,
                'is_default' => false,
            ]);
            $action = 'network.firewall_rule_created';
        } else {
            $rule->fill($attributes)->save();
            $action = 'network.firewall_rule_updated';
        }

        $this->audit->record($action, 'server', $server->id, [
            'rule_id' => $rule->id,
            'name' => $rule->name,
            'action' => $rule->action->value,
            'protocol' => $rule->protocol->value,
            'port' => $rule->port,
            'source' => $rule->source,
        ], $server->organizationId);

        ($this->apply)($server->id);

        return $rule;
    }
}
