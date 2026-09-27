<?php

namespace Kiln\Telemetry\Application\Queries;

use InvalidArgumentException;
use Kiln\Telemetry\Contracts\PromQl;

/**
 * Builds LogQL from structured filters. The organization matcher is always present, so users can
 * never read another organization's streams; raw LogQL is intentionally not accepted.
 */
final class LogQueryBuilder
{
    /**
     * @param  array{server_id?: ?string, site_id?: ?string, service?: ?string, compose_service?: ?string, level?: ?string, search?: ?string, regex?: bool, trace_id?: ?string}  $filters
     */
    public static function build(string $organizationId, array $filters): string
    {
        $matchers = [PromQl::label('kiln_org_id', strtoupper($organizationId))];

        foreach (['server_id' => 'kiln_server_id', 'site_id' => 'kiln_site_id', 'service' => 'service_name'] as $filter => $label) {
            if (($value = $filters[$filter] ?? null) !== null && $value !== '') {
                $matchers[] = PromQl::label($label, $filter === 'service' ? (string) $value : strtoupper((string) $value));
            }
        }

        $query = '{'.implode(', ', $matchers).'}';

        // Compose sites: container logs carry the compose service as structured metadata (kiln.compose.service).
        if (($composeService = $filters['compose_service'] ?? null) !== null && $composeService !== '') {
            $query .= ' | kiln_compose_service='.PromQl::quote((string) $composeService);
        }

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            if (! empty($filters['regex'])) {
                if (@preg_match('~'.str_replace('~', '\~', $search).'~', '') === false) {
                    throw new InvalidArgumentException('The search pattern is not a valid regular expression.');
                }

                $query .= ' |~ '.PromQl::quote($search);
            } else {
                $query .= ' |= '.PromQl::quote($search);
            }
        }

        if (($traceId = $filters['trace_id'] ?? null) !== null && $traceId !== '') {
            $query .= ' | trace_id='.PromQl::quote(strtolower($traceId));
        }

        if (($level = $filters['level'] ?? null) !== null && $level !== '') {
            $pattern = '(?i)'.preg_quote(strtolower($level), '/').'.*';
            $query .= ' | severity_text=~'.PromQl::quote($pattern).' or detected_level=~'.PromQl::quote($pattern);
        }

        return $query;
    }
}
