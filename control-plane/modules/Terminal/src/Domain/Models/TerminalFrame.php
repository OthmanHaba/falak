<?php

namespace Kiln\Terminal\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One recorded asciicast event: 'o' = PTY output (base64 raw bytes), 'r' = resize ("COLSxROWS").
 *
 * @property int $id
 * @property string $session_id
 * @property ?int $fleet_seq
 * @property string $kind
 * @property int $offset_ms
 * @property string $data
 * @property ?Carbon $created_at
 */
class TerminalFrame extends Model
{
    public const OUTPUT = 'o';

    public const RESIZE = 'r';

    public const UPDATED_AT = null;

    protected $table = 'terminal_frames';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fleet_seq' => 'integer', 'offset_ms' => 'integer'];
    }
}
