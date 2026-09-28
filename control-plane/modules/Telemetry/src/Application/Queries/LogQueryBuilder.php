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
     * @param  array{server_id?: ?string, site_id?: ?string, service?: ?string, kind?: ?string, compose_service?: ?string, level?: ?string, search?: ?string, regex?: bool, trace_id?: ?string}  $filters
     */
    public static function build(string $organizationId, array $filters): string
    {
        $matchers = [PromQl::label('kiln_org_id', strtoupper($organizationId))];

        foreach (['server_id' => 'kiln_server_id', 'site_id' => 'kiln_site_id', 'service' => 'service_name'] as $filter => $label) {
            if (($value = $filters[$filter] ?? null) !== null && $value !== '') {
                $matchers[] = PromQl::label($label, $filter === 'service' ? (string) $value : strtoupper((string) $value));
            }
        }

        // kind: "app" (the site's own output) or "access" (edge HTTP access log), see observability/README.md.
        if (($kind = $filters['kind'] ?? null) !== null && $kind !== '') {
            $matchers[] = PromQl::label('kiln_log_kind', (string) $kind);
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

    /**
     * A site's edge access log. Selected by slug (`service_name`), not site id: load balancers route sites that are
     * not deployed on them, and their agents only know the slug of such a site.
     *
     * @param  array{server_id?: ?string, deployment_id?: ?string, method?: ?string, status?: int|string|null, path?: ?string, client_ip?: ?string}  $filters
     */
    public static function access(string $organizationId, string $siteSlug, array $filters): string
    {
        $matchers = [
            PromQl::label('kiln_org_id', strtoupper($organizationId)),
            PromQl::label('service_name', $siteSlug),
            PromQl::label('kiln_log_kind', 'access'),
        ];

        if (($server = $filters['server_id'] ?? null) !== null && $server !== '') {
            $matchers[] = PromQl::label('kiln_server_id', strtoupper((string) $server));
        }

        $query = '{'.implode(', ', $matchers).'}';

        if (($path = $filters['path'] ?? null) !== null && $path !== '') {
            $query .= ' |= '.PromQl::quote((string) $path);
        }

        if (($deployment = $filters['deployment_id'] ?? null) !== null && $deployment !== '') {
            $query .= ' | kiln_deployment_id='.PromQl::quote(strtoupper((string) $deployment));
        }

        if (($method = $filters['method'] ?? null) !== null && $method !== '') {
            $query .= ' | http_request_method='.PromQl::quote(strtoupper((string) $method));
        }

        $status = $filters['status'] ?? null;

        if (is_int($status) || (is_string($status) && preg_match('/^[1-5]\d\d$/', $status) === 1)) {
            $query .= ' | http_response_status_code='.PromQl::quote((string) $status);
        } elseif (is_string($status) && preg_match('/^([1-5])xx$/i', $status, $m) === 1) {
            $query .= ' | http_response_status_code=~'.PromQl::quote($m[1].'..');
        } elseif ($status !== null && $status !== '') {
            throw new InvalidArgumentException('status must be a code (e.g. 404) or a class (e.g. 5xx).');
        }

        if (($ip = $filters['client_ip'] ?? null) !== null && $ip !== '') {
            $query .= ' | client_address='.PromQl::quote((string) $ip);
        }

        return $query;
    }
}
