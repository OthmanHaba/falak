<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Application\CommandLifecycle;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Events\AgentRevoked;
use Kiln\Identity\Contracts\AuditLog;

final class RevokeAgent
{
    public function __construct(
        private readonly CommandLifecycle $lifecycle,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Agent $agent, string $reason): void
    {
        if ($agent->isRevoked()) {
            return;
        }

        DB::transaction(function () use ($agent, $reason) {
            $agent->forceFill([
                'status' => AgentStatus::Revoked,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ])->save();

            $agent->certificates()->whereNull('revoked_at')->update(['revoked_at' => now()]);
        });

        $agent->commands()->whereIn('status', CommandStatus::pending())->each(
            fn ($command) => $this->lifecycle->fail($command, CommandStatus::Cancelled, "Agent revoked: {$reason}"),
        );

        $this->audit->record('agent.revoked', 'server', $agent->server_id, ['agent_id' => $agent->id, 'reason' => $reason], $agent->organization_id);

        AgentRevoked::dispatch($agent->id, $agent->organization_id, $agent->server_id, $reason);
    }
}
