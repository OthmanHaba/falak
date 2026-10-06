<?php

namespace Falak\Sites\Contracts;

use Falak\Sites\Contracts\Exceptions\EnvironmentChanged;

/**
 * Edits of a site's environment variables by other modules (e.g. Secrets promoting a variable to a secret).
 * Every change is a new environment version, audited by key name only.
 */
interface SiteEnvironments
{
    /**
     * Set the given variables (others unchanged) as a new version.
     *
     * @param  array<string, string>  $values
     * @param  int|null  $baseVersion  the version the change was made against; a newer one fails the change
     * @return int|null the new version, or null when nothing changed
     *
     * @throws EnvironmentChanged when $baseVersion is no longer the latest version
     */
    public function set(string $siteId, array $values, ?string $userId, string $auditAction, ?int $baseVersion = null): ?int;
}
