<?php

namespace Kiln\Insights\Contracts\Data;

use DateTimeImmutable;
use Kiln\Insights\Contracts\IssueKind;
use Kiln\Insights\Contracts\IssuePriority;
use Kiln\Insights\Contracts\IssueStatus;

final readonly class IssueData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public ?string $siteId,
        public ?string $serverId,
        public IssueKind $kind,
        public IssueStatus $status,
        public IssuePriority $priority,
        public string $title,
        public ?string $culprit,
        public int $occurrences,
        public int $affectedUsers,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public ?string $assigneeId,
        public string $url,
    ) {}
}
