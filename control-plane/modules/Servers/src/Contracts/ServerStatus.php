<?php

namespace Kiln\Servers\Contracts;

/**
 * creating → provisioning → active; provisioning → needs_attention → provisioning; any → error; any → deleting.
 * "creating" covers machine creation at the provider and waiting for the agent to enroll. "provisioning" covers the
 * machine check and applying the plan; "needs_attention" means the machine check found a conflict Kiln won't resolve
 * on its own, and nothing was applied.
 */
enum ServerStatus: string
{
    case Creating = 'creating';
    case Provisioning = 'provisioning';
    case NeedsAttention = 'needs_attention';
    case Active = 'active';
    case Error = 'error';
    case Deleting = 'deleting';

    public function label(): string
    {
        return match ($this) {
            self::Creating => 'Creating',
            self::Provisioning => 'Provisioning',
            self::NeedsAttention => 'Needs attention',
            self::Active => 'Active',
            self::Error => 'Error',
            self::Deleting => 'Deleting',
        };
    }

    /**
     * Statuses from which the provisioning flow (machine check, then the plan) may start again.
     *
     * @return list<self>
     */
    public static function provisionable(): array
    {
        return [self::Creating, self::Provisioning, self::NeedsAttention, self::Error];
    }
}
