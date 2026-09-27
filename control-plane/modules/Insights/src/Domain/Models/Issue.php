<?php

namespace Kiln\Insights\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Kiln\Insights\Contracts\Data\IssueData;
use Kiln\Insights\Contracts\IssueKind;
use Kiln\Insights\Contracts\IssuePriority;
use Kiln\Insights\Contracts\IssueStatus;

/**
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property ?string $server_id
 * @property IssueKind $kind
 * @property string $fingerprint
 * @property IssueStatus $status
 * @property IssuePriority $priority
 * @property string $title
 * @property ?string $culprit
 * @property ?string $exception_type
 * @property ?string $event_type
 * @property int $occurrences
 * @property int $unhandled_occurrences
 * @property int $affected_users
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property ?Carbon $resolved_at
 * @property ?string $resolved_by
 * @property ?Carbon $ignored_at
 * @property ?Carbon $regressed_at
 * @property int $regressions
 * @property ?string $assignee_id
 * @property ?string $last_trace_id
 * @property ?array<string, mixed> $sample
 * @property ?array<string, mixed> $meta
 * @property Carbon $created_at
 */
class Issue extends Model
{
    use HasUlids;

    protected $table = 'insights_issues';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'priority' => 'none',
        'occurrences' => 0,
        'unhandled_occurrences' => 0,
        'affected_users' => 0,
        'regressions' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => IssueKind::class,
            'status' => IssueStatus::class,
            'priority' => IssuePriority::class,
            'occurrences' => 'integer',
            'unhandled_occurrences' => 'integer',
            'affected_users' => 'integer',
            'regressions' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'ignored_at' => 'datetime',
            'regressed_at' => 'datetime',
            'sample' => 'array',
            'meta' => 'array',
        ];
    }

    /**
     * @return HasMany<IssueComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(IssueComment::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<IssueActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(IssueActivity::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<ExceptionOccurrence, $this>
     */
    public function occurrenceRows(): HasMany
    {
        return $this->hasMany(ExceptionOccurrence::class);
    }

    public function url(): string
    {
        return route('observability.issues.show', $this->id);
    }

    public function record(string $type, ?string $userId = null, array $data = []): IssueActivity
    {
        return $this->activities()->create(['type' => $type, 'user_id' => $userId, 'data' => $data ?: null, 'created_at' => now()]);
    }

    public function toData(): IssueData
    {
        return new IssueData(
            id: $this->id,
            organizationId: $this->organization_id,
            siteId: $this->site_id,
            serverId: $this->server_id,
            kind: $this->kind,
            status: $this->status,
            priority: $this->priority,
            title: $this->title,
            culprit: $this->culprit,
            occurrences: $this->occurrences,
            affectedUsers: $this->affected_users,
            firstSeenAt: $this->first_seen_at->toDateTimeImmutable(),
            lastSeenAt: $this->last_seen_at->toDateTimeImmutable(),
            assigneeId: $this->assignee_id,
            url: $this->url(),
        );
    }
}
