<?php

namespace Falak\Kernel\Security\Casts;

use Illuminate\Database\Eloquent\Model;

/**
 * An array attribute stored as sealed JSON. Replaces Laravel's `encrypted:array` cast.
 */
class SealedArray extends Sealed
{
    /**
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value === null ? null : $this->open($model, $key, (string) $value);
    }

    protected function serialize(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function open(Model $model, string $key, string $value): array
    {
        return (array) json_decode((string) parent::open($model, $key, $value), true, flags: JSON_THROW_ON_ERROR);
    }
}
