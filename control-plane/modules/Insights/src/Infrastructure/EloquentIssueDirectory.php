<?php

namespace Falak\Insights\Infrastructure;

use Falak\Insights\Contracts\Data\IssueData;
use Falak\Insights\Contracts\IssueDirectory;
use Falak\Insights\Contracts\IssueStatus;
use Falak\Insights\Domain\Models\Issue;

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
