<?php

namespace Falak\Kernel\Security\Casts;

use Falak\Kernel\Security\DecryptionFailed;
use Falak\Kernel\Security\Sealer;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Runs before every Eloquent save: a sealed value must open under the row it is about to be written to.
 * Catches values bound to another key (replicate(), a primary key changed after the value was set) at write
 * time, instead of writing something no one can read again.
 */
final class SealedGuard
{
    /** @var array<class-string, list<string>> model class => its sealed columns */
    private static array $columns = [];

    public static function check(Model $model): void
    {
        $columns = self::$columns[$model::class] ??= array_keys(array_filter(
            $model->getCasts(),
            fn ($cast) => is_string($cast) && is_a(explode(':', $cast, 2)[0], Sealed::class, true),
        ));

        if ($columns === []) {
            return;
        }

        // Saving runs before HasUlids assigns the key on create: assign it now, as Eloquent is about to.
        if (! $model->exists && $model->getKey() === null && $model->usesUniqueIds()) {
            $model->setUniqueIds();
        }

        $keyChanged = ! $model->exists || $model->isDirty($model->getKeyName());
        $attributes = $model->getAttributes();

        foreach ($columns as $column) {
            $value = $attributes[$column] ?? null;

            if ($value === null || (! $keyChanged && $value === $model->getRawOriginal($column))) {
                continue;
            }

            try {
                app(Sealer::class)->open((string) $value, Sealed::aad($model, $column));
            } catch (DecryptionFailed $e) {
                throw new LogicException(sprintf('%s::%s holds a value sealed for another row (copied, or the key changed after it was set); set it again before saving.', $model::class, $column), previous: $e);
            }
        }
    }
}
