<?php

namespace Kiln\Alerting\Contracts;

use Kiln\Alerting\Contracts\Data\AlertData;

/**
 * Implement on any module event to have Alerting route it through the organization's alert
 * rules, without Alerting importing the emitting module:
 *
 *     final class DeploymentFailed implements Alertable
 *     {
 *         public function toAlert(): AlertData
 *         {
 *             return new AlertData($this->organizationId, 'deployments.failed', Severity::Critical, "Deploy of {$this->site} failed", dedupKey: "deployment:{$this->id}");
 *         }
 *     }
 *
 * Register the type once (for the rule editor) with {@see AlertTypes::register()}.
 */
interface Alertable
{
    public function toAlert(): AlertData;
}
