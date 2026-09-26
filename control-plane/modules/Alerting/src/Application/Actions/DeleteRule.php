<?php

namespace Kiln\Alerting\Application\Actions;

use Kiln\Alerting\Domain\Models\Rule;
use Kiln\Identity\Contracts\AuditLog;

final class DeleteRule
{
    public function __construct(private readonly AuditLog $audit) {}

    public function __invoke(Rule $rule): void
    {
        $rule->delete();

        $this->audit->record('alerting.rule.deleted', 'alerting_rule', $rule->id, ['name' => $rule->name], $rule->organization_id);
    }
}
