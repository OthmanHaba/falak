<?php

namespace Falak\Telemetry\Infrastructure;

use DateTimeInterface;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Telemetry\Application\Queries\LogQueryBuilder;
use Falak\Telemetry\Contracts\AccessLogs;
use Falak\Telemetry\Contracts\Data\AccessLogEntry;
use Falak\Telemetry\Contracts\Data\LogLine;
use Falak\Telemetry\Contracts\LogsQuery;

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
