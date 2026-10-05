<?php

namespace Falak\Telemetry\Infrastructure\Grafana;

/**
 * Per-organization Grafana object names (uids are limited to 40 characters).
 */
final class GrafanaNames
{
    public static function folderUid(string $organizationId): string
    {
        return substr('falak-org-'.strtolower($organizationId), 0, 40);
    }

    public static function dashboardUid(string $baseUid, string $organizationId): string
    {
        $suffix = '-'.substr(strtolower($organizationId), -10);

        return substr($baseUid, 0, 40 - strlen($suffix)).$suffix;
    }
}
