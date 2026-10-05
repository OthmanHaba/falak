<?php

namespace Falak\Terminal\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Falak\Terminal\Domain\Enums\SessionStatus;

/**
 * @property string $id also the agent-side session_id
 * @property string $organization_id
 * @property string $server_id
 * @property string $server_name
 * @property string $user_id owner
 * @property string $unix_user
 * @property SessionStatus $status
 * @property ?string $command_id terminal.open command
 * @property int $cols
 * @property int $rows
 * @property int $initial_cols terminal size at open (asciicast header)
 * @property int $initial_rows
 * @property int $idle_timeout_s
 * @property bool $shared
 * @property int $channel_epoch live channel generation (rotated on unshare)
 * @property ?Carbon $started_at agent clock of the first started/output event
 * @property ?Carbon $last_activity_at
 * @property ?Carbon $closed_at
 * @property ?string $close_reason exited|closed|idle|timeout|failed|server_deleted
 * @property ?int $exit_code
 * @property ?string $error
 * @property int $recording_bytes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TerminalSession extends Model
{
    use HasUlids;

    protected $table = 'terminal_sessions';

    /** Millisecond precision: recording offsets are computed against started_at. */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'cols' => 'integer',
            'rows' => 'integer',
            'initial_cols' => 'integer',
            'initial_rows' => 'integer',
            'idle_timeout_s' => 'integer',
            'shared' => 'boolean',
            'channel_epoch' => 'integer',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'exit_code' => 'integer',
            'recording_bytes' => 'integer',
        ];
    }

    /**
     * @return HasMany<TerminalFrame, $this>
     */
    public function frames(): HasMany
    {
        return $this->hasMany(TerminalFrame::class, 'session_id')->orderBy('offset_ms')->orderBy('id');
    }

    public function isLive(): bool
    {
        return $this->status->isLive();
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->user_id === $userId;
    }

    /** Milliseconds between the session start (agent clock) and $at, clamped to ≥ 0. */
    public function offsetFor(Carbon $at): int
    {
        $start = $this->started_at ?? $this->created_at;

        return max(0, (int) round(($at->getPreciseTimestamp(3) - $start->getPreciseTimestamp(3))));
    }
}
