<?php

namespace Falak\Security\Domain\Models;

use Falak\Security\Domain\Enums\FixStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One fix applied to a server (security.fix, or a control-plane fix such as a firewall deny rule) and its undo.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $server_id
 * @property string $fix_id
 * @property FixStatus $status
 * @property bool $disruptive
 * @property bool $undoable
 * @property ?string $command_id
 * @property ?string $undo_command_id
 * @property ?string $backup_id the agent's backup, or the firewall rule a control-plane fix created
 * @property ?string $batch_id
 * @property int $position
 * @property ?string $message
 * @property ?string $error
 * @property ?string $applied_by
 * @property ?Carbon $applied_at
 * @property ?string $undone_by
 * @property ?Carbon $undone_at
 * @property Carbon $created_at
 */
class FixRun extends Model
{
    use HasUlids;

    protected $table = 'security_fixes';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FixStatus::class,
            'disruptive' => 'boolean',
            'undoable' => 'boolean',
            'position' => 'integer',
            'applied_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * Whether undo is still possible: applied with a backup, within the undo window.
     */
    public function canUndo(?Carbon $now = null): bool
    {
        return $this->status === FixStatus::Applied && $this->undoable && $this->backup_id !== null && $this->applied_at !== null
            && $this->applied_at->greaterThan(($now ?? now())->subDays((int) config('security.undo_days', 7)));
    }
}
