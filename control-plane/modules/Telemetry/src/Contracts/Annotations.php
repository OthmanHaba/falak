<?php

namespace Kiln\Telemetry\Contracts;

use DateTimeInterface;

/**
 * Grafana annotations (dashboards overlay deployments tagged `kiln`,`deployment`).
 *
 * Calling deployment() again with the same deployment id updates the existing annotation
 * (e.g. started → succeeded extends it into a region ending at $finishedAt).
 * Never throws: annotation failures must not break deployments; returns null when Grafana is
 * not configured or the call failed (the failure is logged).
 */
interface Annotations
{
    /**
     * @param  string  $status  started | succeeded | failed | rolled_back
     * @param  list<string>  $tags  extra tags
     * @return int|null Grafana annotation id
     */
    public function deployment(
        string $organizationId,
        string $deploymentId,
        string $siteId,
        string $status,
        ?string $text = null,
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
        array $tags = [],
    ): ?int;
}
