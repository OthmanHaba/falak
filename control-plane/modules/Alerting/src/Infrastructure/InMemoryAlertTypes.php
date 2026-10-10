<?php

namespace Falak\Alerting\Infrastructure;

use Falak\Alerting\Contracts\AlertTypes;
use Falak\Alerting\Contracts\Severity;

final class InMemoryAlertTypes implements AlertTypes
{
    /** @var array<string, array{type: string, label: string, group: string, severity: Severity, fix: ?string}> */
    private array $types = [];

    public function register(string $type, string $label, string $group, Severity $defaultSeverity = Severity::Warning, ?string $fix = null): void
    {
        $this->types[$type] = ['type' => $type, 'label' => $label, 'group' => $group, 'severity' => $defaultSeverity, 'fix' => $fix];
    }

    public function fix(string $type): ?string
    {
        return $this->types[$type]['fix'] ?? null;
    }

    public function all(): array
    {
        $types = $this->types;
        uasort($types, fn (array $a, array $b) => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);

        return $types;
    }
}
