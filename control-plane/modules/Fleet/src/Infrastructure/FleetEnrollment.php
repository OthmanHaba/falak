<?php

namespace Kiln\Fleet\Infrastructure;

use Kiln\Fleet\Application\Actions\IssueInstallToken;
use Kiln\Fleet\Application\Actions\RevokeAgent;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\Data\InstallToken;
use Kiln\Fleet\Contracts\Enrollment;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\InstallToken as InstallTokenModel;

final class FleetEnrollment implements Enrollment
{
    public function __construct(
        private readonly IssueInstallToken $issue,
        private readonly RevokeAgent $revoke,
    ) {}

    public function issueInstallToken(string $organizationId, ?string $serverId = null, ?int $ttlMinutes = null): InstallToken
    {
        return ($this->issue)($organizationId, $serverId, $ttlMinutes);
    }

    public function revokeServer(string $serverId, string $reason): void
    {
        InstallTokenModel::query()->where('server_id', $serverId)->usable()->update(['expires_at' => now()]);

        Agent::query()
            ->where('server_id', $serverId)
            ->where('status', '!=', AgentStatus::Revoked)
            ->each(fn (Agent $agent) => ($this->revoke)($agent, $reason));
    }
}
