<?php

namespace Falak\Fleet\Application\Actions;

use Illuminate\Support\Facades\DB;
use Falak\Fleet\Application\CommandLifecycle;
use Falak\Fleet\Contracts\AgentStatus;
use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Events\AgentRevoked;
use Falak\Identity\Contracts\AuditLog;

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
