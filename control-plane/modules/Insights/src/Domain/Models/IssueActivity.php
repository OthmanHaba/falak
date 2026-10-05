<?php

namespace Falak\Insights\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Issue timeline: opened, regressed, resolved, ignored, reopened, assigned, priority, commented.
 *
 * @property string $id
 * @property string $issue_id
 * @property ?string $user_id
 * @property string $type
 * @property ?array<string, mixed> $data
 * @property Carbon $created_at
 */
class IssueActivity extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'insights_issue_activities';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
