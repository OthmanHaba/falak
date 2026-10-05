<?php

namespace Falak\Network\Application;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Network\Application\Actions\EnsureDefaultFirewallRules;
use Falak\Network\Domain\Enums\ApplyStatus;
use Falak\Network\Domain\Models\FirewallState;
use Falak\Network\Infrastructure\CanonicalJson;
use Falak\Network\Infrastructure\FirewallCompiler;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Converges a server's firewall: compiles the full desired ruleset and dispatches `net.firewall.apply`
 * only when it differs from what is applied (or already in flight). Every dispatch gets a new
 * revision-based idempotency key, so A → B → A is never answered from the agent's cache.
 *
 * The state row is locked while compiling and dispatching, so concurrent converges (e.g. two servers
 * joining a private network on two workers) serialize: each gets its own revision and the later one
 * always carries the newer ruleset. A server seen for the first time gets its default rules seeded
 * before anything is compiled — otherwise `input_policy: drop` would cut HTTP/HTTPS on existing hosts.
 */
final class ApplyFirewall
{
    public function __construct(
        private readonly FirewallCompiler $compiler,
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
        private readonly EnsureDefaultFirewallRules $defaults,
    ) {}

    public function __invoke(string $serverId, bool $force = false): ?FirewallState
    {
        $server = $this->servers->find($serverId);

        if ($server === null) {
            return null;
        }

        ($this->defaults)($server);

        return DB::transaction(function () use ($server, $serverId, $force) {
            $state = FirewallState::query()->whereKey($serverId)->lockForUpdate()->first() ?? new FirewallState([
                'server_id' => $serverId,
                'organization_id' => $server->organizationId,
                'revision' => 0,
                'status' => ApplyStatus::Pending,
            ]);

            $payload = $this->compiler->compile($serverId);
            $hash = CanonicalJson::hash($payload);

            if (! $force) {
                $converged = $state->status === ApplyStatus::Applied && $state->applied_hash === $hash;
                $inFlight = $state->status === ApplyStatus::Applying && $state->desired_hash === $hash;

                if ($converged || $inFlight) {
                    $state->save();

                    return $state;
                }
            }

            $state->desired_hash = $hash;

            if (! $server->isActive()) {
                // Applied once the server finishes provisioning (ServerProvisioned).
                $state->forceFill(['status' => ApplyStatus::Pending, 'error' => null])->save();

                return $state;
            }

            $state->revision++;

            try {
                $handle = $this->agents->dispatch(
                    $serverId,
                    'net.firewall.apply',
                    $payload,
                    (int) config('network.command_timeout', 120),
                    "net.firewall:{$serverId}:{$state->revision}",
                );

                $state->forceFill(['status' => ApplyStatus::Applying, 'command_id' => $handle->id, 'error' => null]);
            } catch (AgentUnavailable) {
                $state->forceFill(['status' => ApplyStatus::Failed, 'command_id' => null, 'error' => 'The server agent is not connected.']);
            }

            $state->save();

            return $state;
        });
    }
}
