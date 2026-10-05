<?php

namespace Falak\Projects\Contracts;

use Falak\Projects\Contracts\Data\ResolvedVariables;

/**
 * Resolves `${{ <service-name>.<KEY> }}` references in a site's variables against the other services of
 * the same environment (UI_DESIGN §5.3). Database services expose DATABASE_URL, DB_CONNECTION, DB_HOST,
 * DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD; site services expose their own variables (which may
 * reference further services; cycles are reported as errors).
 */
interface VariableReferences
{
    /** Matches one reference; group 1 = service name, group 2 = key. */
    public const PATTERN = '/\$\{\{\s*([A-Za-z0-9][A-Za-z0-9 _.\-]*?)\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /**
     * @param  array<string, string>  $variables  the site's variables (e.g. the environment version being released)
     */
    public function resolve(string $environmentId, string $siteId, array $variables): ResolvedVariables;

    /**
     * Resolve in whatever environment the site is placed in. Variables without references pass through
     * unchanged even when the site is in no environment.
     *
     * @param  array<string, string>  $variables
     */
    public function resolveForSite(string $siteId, array $variables): ResolvedVariables;

    /**
     * References in the variables, without resolving them (canvas edges, reference pickers).
     *
     * @param  array<string, string>  $variables
     * @return list<array{service: string, key: string, variable: string}>
     */
    public function referencesIn(array $variables): array;
}
