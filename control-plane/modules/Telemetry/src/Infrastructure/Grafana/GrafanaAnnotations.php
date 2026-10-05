<?php

namespace Falak\Telemetry\Infrastructure\Grafana;

use DateTimeInterface;
use Falak\Telemetry\Contracts\Annotations;
use Falak\Telemetry\Domain\Models\DeploymentAnnotation;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deployment annotations tagged `falak`,`deployment` (the provisioned dashboards query these tags).
 */
final class GrafanaAnnotations implements Annotations
{
    public function __construct(private readonly GrafanaClient $grafana) {}

    public function deployment(
        string $organizationId,
        string $deploymentId,
        string $siteId,
        string $status,
        ?string $text = null,
        ?DateTimeInterface $startedAt = null,
        ?DateTimeInterface $finishedAt = null,
        array $tags = [],
    ): ?int {
        if (! $this->grafana->configured()) {
            return null;
        }

        $allTags = array_values(array_unique([
            'falak', 'deployment', "site:{$siteId}", "status:{$status}", "org:{$organizationId}", "deployment:{$deploymentId}",
            ...array_map('strval', $tags),
        ]));
        $text ??= "Deployment {$deploymentId} {$status}";

        try {
            $existing = DeploymentAnnotation::query()->find($deploymentId);

            if ($existing && $existing->organization_id === $organizationId) {
                $changes = ['tags' => $allTags, 'text' => $text];

                if ($startedAt) {
                    $changes['time'] = self::ms($startedAt);
                }

                if ($finishedAt) {
                    $changes['timeEnd'] = self::ms($finishedAt);
                }

                $this->grafana->updateAnnotation($existing->grafana_id, $changes);
                $existing->forceFill(['status' => $status])->save();

                return $existing->grafana_id;
            }

            $id = $this->grafana->createAnnotation(
                self::ms($startedAt ?? $finishedAt ?? now()),
                $finishedAt && $startedAt ? self::ms($finishedAt) : null,
                $allTags,
                $text,
            );

            DeploymentAnnotation::query()->updateOrCreate(['deployment_id' => $deploymentId], [
                'organization_id' => $organizationId,
                'site_id' => $siteId,
                'grafana_id' => $id,
                'status' => $status,
            ]);

            return $id;
        } catch (Throwable $e) {
            Log::warning('Grafana deployment annotation failed', ['deployment_id' => $deploymentId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private static function ms(DateTimeInterface $at): int
    {
        return (int) $at->format('Uv');
    }
}
