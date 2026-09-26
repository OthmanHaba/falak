<?php

namespace Kiln\Telemetry\Application\Queries;

use Kiln\Telemetry\Contracts\PromQl;

/**
 * Builds TraceQL from structured filters, always scoped to the organization's resource attribute.
 */
final class TraceQueryBuilder
{
    /**
     * @param  array{site_id?: ?string, service?: ?string, name?: ?string, min_duration_ms?: int|string|null, status?: ?string}  $filters
     */
    public static function build(string $organizationId, array $filters): string
    {
        $conditions = ['resource.kiln.org.id = '.PromQl::quote(strtoupper($organizationId))];

        if (! empty($filters['site_id'])) {
            $conditions[] = 'resource.kiln.site.id = '.PromQl::quote(strtoupper((string) $filters['site_id']));
        }

        if (! empty($filters['service'])) {
            $conditions[] = 'resource.service.name = '.PromQl::quote((string) $filters['service']);
        }

        if (! empty($filters['name'])) {
            $conditions[] = 'name = '.PromQl::quote((string) $filters['name']);
        }

        if (isset($filters['min_duration_ms']) && (int) $filters['min_duration_ms'] > 0) {
            $conditions[] = 'duration >= '.(int) $filters['min_duration_ms'].'ms';
        }

        if (($filters['status'] ?? null) === 'error') {
            $conditions[] = 'status = error';
        }

        return '{ '.implode(' && ', $conditions).' }';
    }
}
