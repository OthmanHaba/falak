<?php

namespace Kiln\Network\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Network\Domain\Enums\ApplyStatus;
use Kiln\Network\Domain\Enums\RuleAction;
use Kiln\Network\Domain\Enums\RuleProtocol;
use Kiln\Network\Domain\Models\FirewallRule;
use Kiln\Network\Domain\Models\FirewallState;
use Kiln\Servers\Contracts\Data\ServerData;

/**
 * Seeds SSH (and HTTP/HTTPS for servers terminating HTTP) once per server. Idempotent: a server
 * that already has a firewall state keeps whatever rules its users left it with.
 */
final class EnsureDefaultFirewallRules
{
    /**
     * @return bool whether defaults were seeded now
     */
    public function __invoke(ServerData $server): bool
    {
        return DB::transaction(function () use ($server) {
            if (FirewallState::query()->whereKey($server->id)->lockForUpdate()->exists()) {
                return false;
            }

            FirewallState::query()->create([
                'server_id' => $server->id,
                'organization_id' => $server->organizationId,
                'revision' => 0,
                'status' => ApplyStatus::Pending,
            ]);

            if (FirewallRule::query()->where('server_id', $server->id)->exists()) {
                return false;
            }

            $defaults = [['SSH', (string) config('network.ssh_port', 22)]];

            if ($server->type->servesHttp()) {
                $defaults[] = ['HTTP', '80'];
                $defaults[] = ['HTTPS', '443'];
            }

            foreach ($defaults as $position => [$name, $port]) {
                FirewallRule::query()->create([
                    'organization_id' => $server->organizationId,
                    'server_id' => $server->id,
                    'name' => $name,
                    'action' => RuleAction::Allow,
                    'protocol' => RuleProtocol::Tcp,
                    'port' => $port,
                    'source' => null,
                    'position' => $position,
                    'is_default' => true,
                ]);
            }

            return true;
        });
    }
}
