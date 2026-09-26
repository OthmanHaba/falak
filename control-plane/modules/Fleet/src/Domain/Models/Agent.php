<?php

namespace Kiln\Fleet\Domain\Models;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\Data\AgentInfo;
use Kiln\Fleet\Database\Factories\AgentFactory;

/**
 * An enrolled kiln-agent. Its id is the CN of its client certificates.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $server_id
 * @property AgentStatus $status
 * @property ?string $hostname
 * @property ?string $arch
 * @property ?string $agent_version
 * @property ?array<string, mixed> $facts
 * @property ?array<string, mixed> $metrics
 * @property Carbon $enrolled_at
 * @property ?Carbon $last_heartbeat_at
 * @property ?string $last_ip
 * @property ?Carbon $revoked_at
 * @property ?string $revocation_reason
 */
#[UseFactory(AgentFactory::class)]
class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'fleet_agents';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'facts' => 'array',
            'metrics' => 'array',
            'enrolled_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * @return HasMany<Command, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(Command::class);
    }

    public function isRevoked(): bool
    {
        return $this->status === AgentStatus::Revoked;
    }

    public function toInfo(): AgentInfo
    {
        $expires = $this->certificates()->whereNull('revoked_at')->max('not_after');
        $metrics = $this->metrics ?? [];

        return new AgentInfo(
            id: $this->id,
            serverId: (string) $this->server_id,
            status: $this->status,
            version: $this->agent_version,
            hostname: $this->hostname,
            arch: $this->arch,
            facts: $this->facts ?? [],
            metrics: $metrics,
            enrolledAt: $this->enrolled_at->toDateTimeImmutable(),
            lastHeartbeatAt: $this->last_heartbeat_at?->toDateTimeImmutable(),
            certificateExpiresAt: $expires ? new DateTimeImmutable((string) $expires) : null,
            runningCommands: array_values(array_map('strval', (array) ($metrics['running_commands'] ?? []))),
        );
    }
}
