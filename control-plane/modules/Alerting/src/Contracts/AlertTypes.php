<?php

namespace Falak\Alerting\Contracts;

/**
 * Registry of alert types offered in the rule editor. Modules register their types in their
 * service provider's boot(), e.g. `$types->register('deployments.failed', 'Deployment failed', 'Deployments', Severity::Critical)`.
 */
interface AlertTypes
{
    public function register(string $type, string $label, string $group, Severity $defaultSeverity = Severity::Warning): void;

    /**
     * @return array<string, array{type: string, label: string, group: string, severity: Severity}>
     */
    public function all(): array;
}
