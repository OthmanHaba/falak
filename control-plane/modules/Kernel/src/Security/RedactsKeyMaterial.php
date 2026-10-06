<?php

namespace Falak\Kernel\Security;

use LogicException;

/**
 * For classes that hold key bytes or provider credentials: var_dump / dump() / print_r show nothing of them,
 * and they can never be serialized (into a queued job, a cache entry or a session).
 */
trait RedactsKeyMaterial
{
    /**
     * @return array<string, bool>
     */
    public function __debugInfo(): array
    {
        return ['redacted' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException(static::class.' holds key material and cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException(static::class.' cannot be unserialized.');
    }
}
