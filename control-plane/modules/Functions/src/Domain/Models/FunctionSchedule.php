<?php

namespace Falak\Functions\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A schedule of a function: runs its scheduled() handler on the leader server, as a cron job of that server.
 *
 * @property string $id
 * @property string $function_id
 * @property string $name
 * @property string $expression
 * @property string $timezone
 * @property 'allow'|'skip' $overlap
 * @property int $timeout_s
 * @property bool $enabled
 */
class FunctionSchedule extends Model
{
    use HasUlids;

    protected $table = 'functions_schedules';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'timeout_s' => 'integer'];
    }

    /** Short, stable key: the cron job is `<slug>.function-<key>` and the runtime sees it as FALAK_SCHEDULE. */
    public function key(): string
    {
        return strtolower(substr($this->id, -8));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(string $slug): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key(),
            'name' => $this->name,
            'expression' => $this->expression,
            'timezone' => $this->timezone,
            'overlap' => $this->overlap,
            'timeout_s' => $this->timeout_s,
            'enabled' => $this->enabled,
            // Insights' heartbeat monitors are keyed by this job name.
            'job' => self::jobName($slug, $this->key()),
        ];
    }

    /** Same naming as Processes' ProgramNames::name (`<slug>.function-<key>`, ≤ 63 characters). */
    public static function jobName(string $slug, string $key): string
    {
        $suffix = "function-{$key}";
        $room = 63 - strlen($suffix) - 1;
        $base = strlen($slug) <= $room ? $slug : rtrim(substr($slug, 0, $room - 7), '-').'-'.substr(sha1($slug), 0, 6);

        return "{$base}.{$suffix}";
    }
}
