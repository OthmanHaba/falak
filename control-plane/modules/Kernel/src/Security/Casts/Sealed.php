<?php

namespace Falak\Kernel\Security\Casts;

use Falak\Kernel\Security\Sealer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A string attribute sealed at rest under the platform data key, bound to "<table>.<column>".
 * Replaces Laravel's `encrypted` cast (keyed by APP_KEY).
 *
 * @implements CastsAttributes<mixed, mixed>
 */
class Sealed implements CastsAttributes
{
    /**
     * @return string|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : $this->open($model, $key, (string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::sealer()->seal($this->serialize($value), self::aad($model, $key));
    }

    /**
     * Two stored values are the same when they open to the same plaintext (every seal uses a new nonce),
     * so re-assigning an unchanged value doesn't make the model dirty.
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return $firstValue === $secondValue;
        }

        return $this->open($model, $key, (string) $firstValue) === $this->open($model, $key, (string) $secondValue);
    }

    public static function aad(Model $model, string $key): string
    {
        return $model->getTable().'.'.$key;
    }

    protected function serialize(mixed $value): string
    {
        return (string) $value;
    }

    protected function open(Model $model, string $key, string $value): mixed
    {
        return self::sealer()->open($value, self::aad($model, $key));
    }

    protected static function sealer(): Sealer
    {
        // app(), not a constructor argument: Eloquent instantiates casts itself (and per request under Octane).
        return app(Sealer::class);
    }
}
