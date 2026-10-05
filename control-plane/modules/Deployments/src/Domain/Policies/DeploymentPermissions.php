<?php

namespace Falak\Deployments\Domain\Policies;

/**
 * Permission names (also Sanctum token abilities).
 */
final class DeploymentPermissions
{
    public const VIEW = 'deployments.view';

    public const CREATE = 'deployments.create';

    public const ROLLBACK = 'deployments.rollback';

    public const MANAGE = 'deployments.manage';
}
