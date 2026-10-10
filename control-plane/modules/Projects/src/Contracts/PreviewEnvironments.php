<?php

namespace Falak\Projects\Contracts;

use Falak\Projects\Contracts\Data\EnvironmentData;
use Illuminate\Validation\ValidationException;

/**
 * Preview environments of pull requests, for Previews (which owns their lifecycle). A preview is a fork of a base
 * environment flagged `is_preview`: its sites resolve only secrets available to previews (none for a fork's pull
 * request), and the services it shares resolve in the base environment.
 */
interface PreviewEnvironments
{
    /**
     * Fork $baseEnvironmentId into a new preview environment: the sites named in $include are duplicated (the canvas
     * position and name kept, $siteOverrides per service name: branch, server_ids, name_suffix, only_variables,
     * references…; always isolated (own Linux user), push-to-deploy off, literal secret values dropped). A fork's
     * preview shares nothing ($shared ignored). Databases are not copied: Previews creates them per strategy and places
     * them with {@see place()}.
     *
     * @param  list<string>  $include  service names to run in the preview
     * @param  list<string>  $shared  service names resolved in the base environment
     * @param  array<string, array<string, mixed>>  $siteOverrides
     * @return array{environment: EnvironmentData, sites: array<string, string>, warnings: list<string>} sites: service name => site id
     *
     * @throws ValidationException
     */
    public function create(string $baseEnvironmentId, string $name, bool $fork, array $include, array $shared, array $siteOverrides, ?string $userId = null): array;

    /** Place a site / database Previews created into the preview environment (idempotent). */
    public function place(string $environmentId, ServiceKind $kind, string $refId, string $name, int $x = 0, int $y = 0): void;

    /**
     * Delete a preview environment and what is still placed in it (its sites and databases are deleted by Previews
     * first; this removes the rows). Refuses environments that are not previews.
     *
     * @throws ValidationException
     */
    public function delete(string $environmentId): void;
}
