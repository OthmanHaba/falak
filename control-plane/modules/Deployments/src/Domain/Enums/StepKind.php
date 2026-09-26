<?php

namespace Kiln\Deployments\Domain\Enums;

enum StepKind: string
{
    case Build = 'build';
    case Hook = 'hook';
    case Fetch = 'fetch';
    case Prepare = 'prepare';
    case Activate = 'activate';
    /** Manual rollback: point `current` at an earlier release. */
    case Switch = 'switch';
    case Swap = 'swap';
    case Restart = 'restart';
    case HealthCheck = 'healthcheck';
    /** Failure rollback of an activated server. */
    case Revert = 'revert';
    case RevertSwap = 'revert_swap';
    case RevertRestart = 'revert_restart';

    public function commandType(): ?string
    {
        return match ($this) {
            self::Hook => 'deploy.hook',
            self::Fetch => 'deploy.fetch',
            self::Prepare => 'deploy.prepare',
            self::Activate => 'deploy.activate',
            self::Switch, self::Revert => 'deploy.rollback',
            self::Swap, self::RevertSwap => 'deploy.container.swap',
            self::Restart, self::RevertRestart => 'proc.restart',
            self::Build, self::HealthCheck => null,
        };
    }

    /** Steps that change which release serves traffic on the server. */
    public function activates(): bool
    {
        return in_array($this, [self::Activate, self::Switch, self::Swap], true);
    }
}
