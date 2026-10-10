<?php

namespace Falak\Alerting\Application\Actions;

use Falak\Alerting\Domain\Models\Rule;
use Falak\Identity\Contracts\AuditLog;

final class SaveRule
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function __invoke(string $organizationId, array $data, ?Rule $rule = null): Rule
    {
        $rule ??= new Rule(['organization_id' => $organizationId]);
        $created = ! $rule->exists;

        $quiet = $data['quiet_hours'] ?? null;

        $rule->fill([
            'name' => $data['name'],
            'event_types' => array_values(array_unique((array) $data['event_types'])),
            'min_severity' => $data['min_severity'],
            'enabled' => (bool) ($data['enabled'] ?? true),
            'quiet_hours' => $quiet && ($quiet['enabled'] ?? false) ? [
                'start' => $quiet['start'],
                'end' => $quiet['end'],
                'timezone' => $quiet['timezone'] ?? 'UTC',
                'days' => array_values(array_map('intval', (array) ($quiet['days'] ?? []))),
                'allow_critical' => (bool) ($quiet['allow_critical'] ?? true),
            ] : null,
            'rate_limit_per_hour' => $data['rate_limit_per_hour'] ?? null,
            'user_modified' => $rule->pack_key !== null,
        ])->save();

        $rule->channels()->sync(array_values((array) ($data['channel_ids'] ?? [])));

        $this->audit->record($created ? 'alerting.rule.created' : 'alerting.rule.updated', 'alerting_rule', $rule->id, [
            'name' => $rule->name,
            'event_types' => $rule->event_types,
            'min_severity' => $rule->min_severity->value,
            'channels' => count((array) ($data['channel_ids'] ?? [])),
        ], $organizationId);

        return $rule;
    }
}
