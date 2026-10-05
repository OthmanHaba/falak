<?php

namespace Falak\Insights\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $issue_id
 * @property string $user_id
 * @property string $body
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class IssueComment extends Model
{
    use HasUlids;

    protected $table = 'insights_issue_comments';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return BelongsTo<Issue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }
}
