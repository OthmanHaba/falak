<?php

use Falak\Alerting\Contracts\Severity;
use Falak\Alerting\Domain\Models\Rule;

function rule_with(array $types, Severity $min = Severity::Info): Rule
{
    return (new Rule)->forceFill(['event_types' => $types, 'min_severity' => $min]);
}

it('matches exact types, group wildcards and the catch-all', function () {
    expect(rule_with(['fleet.agent_offline'])->matches('fleet.agent_offline', Severity::Critical))->toBeTrue()
        ->and(rule_with(['fleet.agent_offline'])->matches('fleet.agent_online', Severity::Critical))->toBeFalse()
        ->and(rule_with(['insights.*'])->matches('insights.issue_opened', Severity::Info))->toBeTrue()
        ->and(rule_with(['insights.*'])->matches('insightsx.issue', Severity::Info))->toBeFalse()
        ->and(rule_with(['*'])->matches('deployments.failed', Severity::Info))->toBeTrue();
});

it('respects the minimum severity', function () {
    $rule = rule_with(['*'], Severity::Warning);

    expect($rule->matches('x.y', Severity::Info))->toBeFalse()
        ->and($rule->matches('x.y', Severity::Warning))->toBeTrue()
        ->and($rule->matches('x.y', Severity::Critical))->toBeTrue();
});
