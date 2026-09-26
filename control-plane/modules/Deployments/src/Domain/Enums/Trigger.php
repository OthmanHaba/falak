<?php

namespace Kiln\Deployments\Domain\Enums;

enum Trigger: string
{
    case Manual = 'manual';
    case Push = 'push';
    case Api = 'api';
    case Hook = 'hook';
    case Rollback = 'rollback';

    /** deploy.* `context.trigger` value (the agent schema has no "hook"). */
    public function agentValue(): string
    {
        return $this === self::Hook ? 'api' : $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Push => 'Push',
            self::Api => 'API',
            self::Hook => 'Deploy hook',
            self::Rollback => 'Rollback',
        };
    }
}
