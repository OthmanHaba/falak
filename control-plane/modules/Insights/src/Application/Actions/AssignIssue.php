<?php

namespace Falak\Insights\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Insights\Domain\Models\Issue;

final class AssignIssue
{
    public function __construct(
        private readonly OrganizationAccess $access,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @throws ValidationException when the assignee is not a member of the issue's organization
     */
    public function __invoke(Issue $issue, ?string $assigneeId, string $userId): void
    {
        if ($assigneeId !== null && ! $this->access->isMember($assigneeId, $issue->organization_id)) {
            throw ValidationException::withMessages(['assignee_id' => 'The assignee must be a member of the organization.']);
        }

        if ($issue->assignee_id === $assigneeId) {
            return;
        }

        $issue->forceFill(['assignee_id' => $assigneeId])->save();
        $issue->record($assigneeId ? 'assigned' : 'unassigned', $userId, $assigneeId ? ['assignee_id' => $assigneeId] : []);
        $this->audit->record('insights.issue.assigned', 'insights_issue', $issue->id, ['assignee_id' => $assigneeId], $issue->organization_id, $userId);
    }
}
