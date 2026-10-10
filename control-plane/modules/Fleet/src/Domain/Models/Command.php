<?php

namespace Falak\Fleet\Domain\Models;

use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Data\CommandResult;
use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A command queued for an agent. The payload is stored as canonical JSON, encrypted at rest
 * (payloads may carry secrets such as environment variables).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $agent_id
 * @property string $server_id
 * @property string $type
 * @property string $payload JSON object
 * @property int $timeout_s
 * @property string $idempotency_key
 * @property CommandStatus $status
 * @property int $attempts
 * @property ?int $exit_code
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property Carbon $queued_at
 * @property ?Carbon $delivered_at
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?string $delivered_session
 */
class Command extends Model
{
    use HasUlids;

    protected $table = 'fleet_commands';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /**
     * Command types whose output is private to the module that issued them (PTY streams can
     * contain typed secrets); they are never readable through Fleet's generic command views.
     *
     * @var list<string>
     */
    public const PRIVATE_OUTPUT_PREFIXES = ['terminal.'];

    public function hasPrivateOutput(): bool
    {
        foreach (self::PRIVATE_OUTPUT_PREFIXES as $prefix) {
            if (str_starts_with($this->type, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => Sealed::class,
            'status' => CommandStatus::class,
            'result' => 'array',
            'queued_at' => 'datetime',
            'delivered_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return HasMany<CommandEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CommandEvent::class);
    }

    /**
     * Envelope per contracts/agent-protocol/envelope.schema.json.
     */
    public function envelope(): object
    {
        $payload = json_decode($this->payload, false, 512, JSON_THROW_ON_ERROR);

        return (object) [
            'id' => $this->id,
            'type' => $this->type,
            'timeout_s' => $this->timeout_s,
            'idempotency_key' => $this->idempotency_key,
            'payload' => is_object($payload) ? $payload : (object) [],
        ];
    }

    public function toHandle(): CommandHandle
    {
        return new CommandHandle($this->id, $this->server_id, $this->agent_id, $this->type, $this->idempotency_key);
    }

    public function toResult(): CommandResult
    {
        return new CommandResult(
            id: $this->id,
            serverId: $this->server_id,
            type: $this->type,
            status: $this->status,
            exitCode: $this->exit_code,
            result: $this->result,
            error: $this->error,
            startedAt: $this->started_at?->toDateTimeImmutable(),
            finishedAt: $this->finished_at?->toDateTimeImmutable(),
        );
    }
}
