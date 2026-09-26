<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\InstallToken;
use Kiln\Fleet\Events\AgentEnrolled;
use Kiln\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Kiln\Fleet\Infrastructure\Pki\InvalidCsr;
use Kiln\Fleet\Infrastructure\Pki\IssuedCertificate;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Consumes a one-time install token, signs the agent's CSR and registers the agent.
 * Any previous agent of the same server is revoked (re-install).
 */
final class EnrollAgent
{
    public function __construct(
        private readonly CertificateAuthorityService $ca,
        private readonly RevokeAgent $revoke,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $facts
     * @return array{agent: Agent, certificate: IssuedCertificate}
     *
     * @throws AuthenticationException invalid, used or expired token
     * @throws InvalidCsr
     */
    public function __invoke(string $token, string $csrPem, array $facts, ?string $ip = null): array
    {
        $agentId = (string) Str::ulid();

        [$agent, $certificate, $previous] = DB::transaction(function () use ($token, $csrPem, $facts, $ip, $agentId) {
            $installToken = InstallToken::query()->where('token_hash', InstallToken::hash($token))->lockForUpdate()->first();

            if (! $installToken || $installToken->used_at !== null || $installToken->expires_at->isPast()) {
                throw new AuthenticationException('The enrollment token is invalid, expired or already used.');
            }

            // Sign before consuming so an invalid CSR does not burn the token.
            $certificate = $this->ca->signAgentCsr($csrPem, $agentId, $installToken->organization_id);

            $consumed = InstallToken::query()->whereKey($installToken->id)->whereNull('used_at')
                ->update(['used_at' => now(), 'agent_id' => $agentId]);

            if ($consumed !== 1) {
                throw new AuthenticationException('The enrollment token was already used.');
            }

            $previous = $installToken->server_id
                ? Agent::query()->where('server_id', $installToken->server_id)->where('status', '!=', AgentStatus::Revoked)->get()
                : collect();

            $agent = Agent::query()->create([
                'id' => $agentId,
                'organization_id' => $installToken->organization_id,
                'server_id' => $installToken->server_id,
                'status' => AgentStatus::Online,
                'hostname' => $facts['hostname'] ?? null,
                'arch' => $facts['arch'] ?? null,
                'agent_version' => $facts['agent_version'] ?? null,
                'facts' => $facts,
                'enrolled_at' => now(),
                'last_heartbeat_at' => now(),
                'last_ip' => $ip,
            ]);

            $agent->certificates()->create([
                'serial' => $certificate->serial,
                'fingerprint' => $certificate->fingerprint,
                'certificate_pem' => $certificate->pem,
                'not_before' => $certificate->notBefore,
                'not_after' => $certificate->notAfter,
            ]);

            return [$agent, $certificate, $previous];
        });

        foreach ($previous as $old) {
            ($this->revoke)($old, 'replaced by re-enrollment');
        }

        $this->audit->record('agent.enrolled', 'server', $agent->server_id, [
            'agent_id' => $agent->id,
            'hostname' => $agent->hostname,
            'ip' => $ip,
        ], $agent->organization_id);

        AgentEnrolled::dispatch($agent->id, $agent->organization_id, $agent->server_id, $facts);

        return ['agent' => $agent, 'certificate' => $certificate];
    }
}
