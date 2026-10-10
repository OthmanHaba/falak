<?php

namespace Falak\Alerting\Contracts;

/**
 * Registry of alert types offered in the rule editor. Modules register their types in their
 * service provider's boot(), e.g. `$types->register('deployments.failed', 'Deployment failed', 'Deployments', Severity::Critical)`.
 *
 * Every group whose types reach Warning gets a rule in the organizations' default rule pack (DefaultRulePack), so a
 * module's new group joins it without changes here. $fix labels the suggested fix behind the alerts' links (e.g.
 * "Grow volume"); an alert may override it ({@see Data\AlertData::$action}).
 */
interface AlertTypes
{
    public function register(string $type, string $label, string $group, Severity $defaultSeverity = Severity::Warning, ?string $fix = null): void;

    /**
     * @return array<string, array{type: string, label: string, group: string, severity: Severity, fix: ?string}>
     */
    public function all(): array;

    /** The suggested fix registered for $type (null: none, or an unknown type). */
    public function fix(string $type): ?string;
}
