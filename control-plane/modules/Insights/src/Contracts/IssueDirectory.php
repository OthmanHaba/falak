<?php

namespace Falak\Insights\Contracts;

use Falak\Insights\Contracts\Data\IssueData;

/**
 * Read-only issue lookups for other modules.
 */
interface IssueDirectory
{
    public function find(string $issueId): ?IssueData;

    /**
     * Open issues of an organization (optionally one site), most recently seen first.
     *
     * @return list<IssueData>
     */
    public function open(string $organizationId, ?string $siteId = null, int $limit = 50): array;
}
