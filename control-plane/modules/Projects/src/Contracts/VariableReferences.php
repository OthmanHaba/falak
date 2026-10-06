<?php

namespace Falak\Projects\Contracts;

use Falak\Projects\Contracts\Data\ResolvedVariables;

/**
 * Resolves `${{ <service-name>.<KEY> }}` references in a site's variables against the other services of
 * the same environment (UI_DESIGN §5.3). Database services expose DATABASE_URL, DB_CONNECTION, DB_HOST,
 * DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD; site services expose their own variables (which may
 * reference further services; cycles are reported as errors).
 *
 * `${{ secrets.NAME }}` is the secret store (Secrets module): the nearest secret NAME of the service that owns
 * the variable (service, environment, project, organization). `secrets` is never a service name here.
 */
interface VariableReferences
{
    /** Matches one reference; group 1 = service name, group 2 = key. */
    public const PATTERN = '/\$\{\{\s*([A-Za-z0-9][A-Za-z0-9 _.\-]*?)\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /** The reference namespace of the secret store: `${{ secrets.NAME }}`. */
    public const SECRETS = 'secrets';

    /**
     * @param  array<string, string>  $variables  the site's variables (e.g. the environment version being released)
     */
    public function resolve(string $environmentId, string $siteId, array $variables): ResolvedVariables;

    /**
     * Resolve in whatever environment the site is placed in. Variables without references pass through
     * unchanged even when the site is in no environment.
     *
     * @param  array<string, string>  $variables
     * @param  list<string>|null  $only  resolve and return only these variables (self-references still see all)
     * @param  bool  $forPreview  a preview environment: secrets not available to previews count as missing
     */
    public function resolveForSite(string $siteId, array $variables, ?array $only = null, bool $forPreview = false): ResolvedVariables;

    /**
     * The errors resolveForSite() would report, without reading any secret (nothing decrypted or logged as an
     * access): for previews of the variables in the UI.
     *
     * @param  array<string, string>  $variables
     * @return list<string>
     */
    public function check(string $siteId, array $variables): array;

    /**
     * References in the variables, without resolving them (canvas edges, reference pickers).
     *
     * @param  array<string, string>  $variables
     * @return list<array{service: string, key: string, variable: string}>
     */
    public function referencesIn(array $variables): array;
}
