<?php

namespace Kiln\Alerting\Infrastructure;

use Kiln\Alerting\Contracts\AlertTypes;
use Kiln\Alerting\Contracts\Severity;

final class InMemoryAlertTypes implements AlertTypes
{
    /** @var array<string, array{type: string, label: string, group: string, severity: Severity}> */
    private array $types = [];

    public function register(string $type, string $label, string $group, Severity $defaultSeverity = Severity::Warning): void
    {
        $this->types[$type] = ['type' => $type, 'label' => $label, 'group' => $group, 'severity' => $defaultSeverity];
    }

    public function all(): array
    {
        $types = $this->types;
        uasort($types, fn (array $a, array $b) => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);

        return $types;
    }
}
