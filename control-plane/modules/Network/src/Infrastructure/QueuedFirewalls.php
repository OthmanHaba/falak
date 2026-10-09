<?php

namespace Falak\Network\Infrastructure;

use Falak\Network\Application\Actions\DeleteFirewallRule;
use Falak\Network\Application\Actions\SaveFirewallRule;
use Falak\Network\Application\ApplyFirewall;
use Falak\Network\Contracts\Firewalls;
use Falak\Network\Domain\Enums\RuleAction;
use Falak\Network\Domain\Enums\RuleProtocol;
use Falak\Network\Domain\Models\FirewallRule;
use Falak\Servers\Contracts\ServerDirectory;
use InvalidArgumentException;

final class QueuedFirewalls implements Firewalls
{
    public function __construct(
        private readonly ApplyFirewall $apply,
        private readonly FirewallCompiler $compiler,
        private readonly ServerDirectory $servers,
    ) {}

    public function converge(string $serverId): void
    {
        ($this->apply)(strtolower($serverId));
    }

    public function reapply(string $serverId): void
    {
        ($this->apply)(strtolower($serverId), force: true);
    }

    public function expectedPorts(string $serverId): array
    {
        $payload = $this->compiler->compile(strtolower($serverId));
        $ports = ["tcp/{$payload['ssh_port']}"];

        foreach ($payload['rules'] as $rule) {
            if ($rule['action'] !== 'accept' || isset($rule['interface'])) {
                continue;
            }

            foreach ($rule['ports'] ?? ['*'] as $port) {
                $ports[] = "{$rule['protocol']}/{$port}";
            }
        }

        return array_values(array_unique($ports));
    }

    public function deniedPorts(string $serverId): array
    {
        $ports = [];

        $rules = FirewallRule::query()
            ->where('server_id', strtolower($serverId))
            ->where('action', RuleAction::Deny->value)
            ->whereNull('source')
            ->whereNotNull('port')
            ->get();

        foreach ($rules as $rule) {
            foreach ($rule->protocol === RuleProtocol::Any ? ['tcp', 'udp'] : [$rule->protocol->value] as $protocol) {
                $ports[] = "{$protocol}/{$rule->port}";
            }
        }

        return array_values(array_unique($ports));
    }

    public function denyPort(string $serverId, string $protocol, int $port, string $name): string
    {
        $server = $this->servers->find(strtolower($serverId)) ?? throw new InvalidArgumentException("Unknown server {$serverId}.");
        $proto = RuleProtocol::from($protocol);

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException("Invalid port {$port}.");
        }

        $existing = FirewallRule::query()
            ->where('server_id', $server->id)
            ->where('action', RuleAction::Deny->value)
            ->where('protocol', $proto->value)
            ->where('port', (string) $port)
            ->whereNull('source')
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        return app(SaveFirewallRule::class)($server, ['name' => mb_substr($name, 0, 120), 'action' => RuleAction::Deny->value, 'protocol' => $proto->value, 'port' => (string) $port])->id;
    }

    public function deleteRule(string $serverId, string $ruleId): bool
    {
        $rule = FirewallRule::query()->where('server_id', strtolower($serverId))->whereKey($ruleId)->first();

        if ($rule === null) {
            return false;
        }

        app(DeleteFirewallRule::class)($rule);

        return true;
    }
}
