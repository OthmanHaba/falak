<?php

namespace Falak\Databases\Domain\Enums;

/**
 * SQL engines hold databases, users and grants; key-value engines (Redis, Valkey) hold instances, one process each,
 * with a single `default` user (the requirepass password).
 */
enum EngineKind: string
{
    case Sql = 'sql';
    case KeyValue = 'key_value';

    /**
     * @return list<Engine>
     */
    public function engines(): array
    {
        return array_values(array_filter(Engine::cases(), fn (Engine $engine) => $engine->kind() === $this));
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(fn (Engine $engine) => $engine->value, $this->engines());
    }
}
