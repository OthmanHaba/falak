<?php

namespace Falak\Insights\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Insights\Contracts\IssuePriority;
use Falak\Insights\Domain\Models\Issue;

final class SetIssuePriority
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Issue $issue, IssuePriority $priority, string $userId): void
    {
        if ($issue->priority === $priority) {
            return;
        }

        $from = $issue->priority;
        $issue->forceFill(['priority' => $priority])->save();
        $issue->record('priority', $userId, ['from' => $from->value, 'to' => $priority->value]);
        $this->audit->record('insights.issue.priority', 'insights_issue', $issue->id, ['from' => $from->value, 'to' => $priority->value], $issue->organization_id, $userId);
    }
}
