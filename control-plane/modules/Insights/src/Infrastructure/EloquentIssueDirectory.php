<?php

namespace Kiln\Insights\Infrastructure;

use Kiln\Insights\Contracts\Data\IssueData;
use Kiln\Insights\Contracts\IssueDirectory;
use Kiln\Insights\Contracts\IssueStatus;
use Kiln\Insights\Domain\Models\Issue;

final class EloquentIssueDirectory implements IssueDirectory
{
    public function find(string $issueId): ?IssueData
    {
        return Issue::query()->find($issueId)?->toData();
    }

    public function open(string $organizationId, ?string $siteId = null, int $limit = 50): array
    {
        return Issue::query()
            ->where('organization_id', $organizationId)
            ->where('status', IssueStatus::Open)
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get()
            ->map(fn (Issue $issue) => $issue->toData())
            ->all();
    }
}
