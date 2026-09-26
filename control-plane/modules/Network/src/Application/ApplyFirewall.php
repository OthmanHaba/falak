<?php

namespace Kiln\Network\Application;

use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Network\Domain\Enums\ApplyStatus;
use Kiln\Network\Domain\Models\FirewallState;
use Kiln\Network\Infrastructure\CanonicalJson;
use Kiln\Network\Infrastructure\FirewallCompiler;
use Kiln\Servers\Contracts\ServerDirectory;

/**
 * Converges a server's firewall: compiles the full desired ruleset and dispatches `net.firewall.apply`
 * only when it differs from what is applied (or already in flight). Every dispatch gets a new
 * revision-based idempotency key, so A → B → A is never answered from the agent's cache.
 */
final class ApplyFirewall
{
    public function __construct(
        private readonly FirewallCompiler $compiler,
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
    ) {}

    public function __invoke(string $serverId, bool $force = false): ?FirewallState
    {
        $server = $this->servers->find($serverId);

        if ($server === null) {
            return null;
        }

        $payload = $this->compiler->compile($serverId);
        $hash = CanonicalJson::hash($payload);

        $state = FirewallState::query()->find($serverId) ?? new FirewallState([
            'server_id' => $serverId,
            'organization_id' => $server->organizationId,
            'revision' => 0,
            'status' => ApplyStatus::Pending,
        ]);

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
    }
}
