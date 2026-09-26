<?php

namespace Kiln\Insights\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Insights\Domain\Models\Threshold;

final class SaveThreshold
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array{event_type: string, name_pattern?: ?string, metric: string, threshold_ms: float|int|string, window_minutes: int|string, min_count?: int|string|null, enabled?: bool}  $data
     */
    public function __invoke(string $organizationId, string $siteId, array $data, string $userId, ?Threshold $threshold = null): Threshold
    {
        $attributes = [
            'event_type' => $data['event_type'],
            'name_pattern' => isset($data['name_pattern']) && trim((string) $data['name_pattern']) !== '' ? trim((string) $data['name_pattern']) : null,
            'metric' => $data['metric'],
            'threshold_ms' => (float) $data['threshold_ms'],
            'window_minutes' => (int) $data['window_minutes'],
            'min_count' => max(1, (int) ($data['min_count'] ?? 1)),
            'enabled' => (bool) ($data['enabled'] ?? true),
        ];

        $threshold ??= new Threshold(['organization_id' => $organizationId, 'site_id' => $siteId]);
        $created = ! $threshold->exists;
        $threshold->forceFill($attributes)->save();

        $this->audit->record($created ? 'insights.threshold.created' : 'insights.threshold.updated', 'insights_threshold', $threshold->id, [...$attributes, 'site_id' => $siteId], $organizationId, $userId);

        return $threshold;
    }
}
