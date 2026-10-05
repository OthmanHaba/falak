<?php

namespace Falak\Alerting\Application\Actions;

use Falak\Alerting\Domain\Models\Rule;
use Falak\Identity\Contracts\AuditLog;

final class DeleteRule
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Rule $rule): void
    {
        $rule->delete();

        $this->audit->record('alerting.rule.deleted', 'alerting_rule', $rule->id, ['name' => $rule->name], $rule->organization_id);
    }
}
