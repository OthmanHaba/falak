<?php

namespace Kiln\Telemetry\Infrastructure;

use DateTimeInterface;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Telemetry\Application\Queries\LogQueryBuilder;
use Kiln\Telemetry\Contracts\AccessLogs;
use Kiln\Telemetry\Contracts\Data\AccessLogEntry;
use Kiln\Telemetry\Contracts\Data\LogLine;
use Kiln\Telemetry\Contracts\LogsQuery;

final class LokiAccessLogs implements AccessLogs
{
    public function __construct(
        private readonly LogsQuery $logs,
        private readonly SiteDirectory $sites,
    ) {}

    public function forSite(string $organizationId, string $siteId, DateTimeInterface $from, DateTimeInterface $to, array $filters = [], int $limit = 200): array
    {
        $site = $this->sites->find(strtolower($siteId));

        if ($site === null || $site->organizationId !== strtolower($organizationId)) {
            return [];
        }

        $logql = LogQueryBuilder::access($organizationId, $site->slug, $filters);
        $lines = $this->logs->queryRange($logql, $from, $to, max(1, min(1000, $limit)), 'backward');

        return array_map(fn (LogLine $line) => AccessLogEntry::fromLogLine($line), $lines);
    }
}
