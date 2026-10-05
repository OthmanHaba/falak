<?php

namespace Falak\Fleet\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $command_id
 * @property int $seq
 * @property string $kind
 * @property ?string $stream
 * @property ?string $data
 * @property ?float $progress
 * @property ?int $exit_code
 * @property ?array<string, mixed> $result
 * @property ?string $error
 * @property Carbon $at
 */
class CommandEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'fleet_command_events';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['result' => 'array', 'at' => 'datetime', 'progress' => 'float'];
    }
}
