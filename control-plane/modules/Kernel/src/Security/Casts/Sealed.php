<?php

namespace Falak\Kernel\Security\Casts;

use Falak\Kernel\Security\Sealer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A string attribute sealed at rest under the platform data key, bound to its row: the AAD is
 * "<table>.<column>:<primary key>", so a value copied to another column or another row (another
 * organization's release, user or webhook) fails to open. Replaces Laravel's `encrypted` cast (APP_KEY).
 *
 * The primary key must be known when the attribute is set: models with ULIDs/UUIDs get theirs assigned on the
 * spot, others must set their key first. SealedGuard refuses to save a value bound to another key (e.g. after
 * replicate() or a key change).
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

    /**
     * @return array<string, mixed> the sealed column, plus the primary key when it was assigned here (Eloquent
     *                              merges the returned attributes over a copy taken before this call)
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        if ($model->getKey() === null && $model->usesUniqueIds()) {
            $model->setUniqueIds();
        }

        $sealed = self::sealer()->seal($this->serialize($value), self::aad($model, $key));

        return $model->getKey() === null ? [$key => $sealed] : [$key => $sealed, $model->getKeyName() => $model->getKey()];
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
        $id = $model->getKey();

        if ($id === null || $id === '') {
            throw new LogicException(sprintf('%s::%s is sealed to its row: set the primary key (%s) before it.', $model::class, $key, $model->getKeyName()));
        }

        return self::aadFor($model->getTable(), $key, (string) $id);
    }

    public static function aadFor(string $table, string $column, string $id): string
    {
        return "{$table}.{$column}:{$id}";
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
