<?php

namespace Kiln\Insights\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Insights\Application\IssueTracker;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Domain\Models\Issue;

final class ChangeIssueStatus
{
    public function __construct(
        private readonly IssueTracker $issues,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Issue $issue, IssueStatus $status, string $userId): void
    {
        if ($issue->status === $status) {
            return;
        }

        $from = $issue->status;

        match ($status) {
            IssueStatus::Resolved => $this->issues->resolve($issue, $userId),
            IssueStatus::Ignored => $this->ignore($issue, $userId),
            IssueStatus::Open => $this->reopen($issue, $userId),
        };

        $this->audit->record("insights.issue.{$status->value}", 'insights_issue', $issue->id, ['from' => $from->value, 'title' => $issue->title], $issue->organization_id, $userId);
    }

    private function ignore(Issue $issue, string $userId): void
    {
        $issue->forceFill(['status' => IssueStatus::Ignored, 'ignored_at' => now(), 'resolved_at' => null, 'resolved_by' => null])->save();
        $issue->record('ignored', $userId);
    }

    private function reopen(Issue $issue, string $userId): void
    {
        $issue->forceFill(['status' => IssueStatus::Open, 'ignored_at' => null, 'resolved_at' => null, 'resolved_by' => null])->save();
        $issue->record('reopened', $userId);
    }
}
