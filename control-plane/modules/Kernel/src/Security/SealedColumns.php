<?php

namespace Falak\Kernel\Security;

use Closure;
use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;

/**
 * Every sealed column: those of model casts (Sealed / SealedArray), found by reading the casts of the models
 * in modules/<Module>/src/Domain/Models, plus columns sealed another way that modules register (e.g. by a
 * SealedEncrypter). Also batch re-sealing of a column (data-key rotation).
 */
class SealedColumns
{
    /** @var array<string, array{table: string, primary_key: string, column: string, aad: string}> */
    private static array $registered = [];

    /** A column a module seals itself, with the AAD it uses. */
    public static function register(string $table, string $primaryKey, string $column, string $aad): void
    {
        self::$registered["{$table}.{$column}"] = ['table' => $table, 'primary_key' => $primaryKey, 'column' => $column, 'aad' => $aad];
    }

    /**
     * @return list<array{table: string, primary_key: string, column: string, aad: string}>
     */
    public function all(): array
    {
        $columns = array_values(self::$registered);

        foreach (glob(base_path('modules/*/src/Domain/Models/*.php')) ?: [] as $file) {
            $class = 'Falak\\'.basename(dirname($file, 4)).'\\Domain\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;

            foreach ($model->getCasts() as $column => $cast) {
                if (is_string($cast) && is_a(explode(':', $cast, 2)[0], Sealed::class, true)) {
                    $columns[] = ['table' => $model->getTable(), 'primary_key' => $model->getKeyName(), 'column' => $column, 'aad' => Sealed::aad($model, $column)];
                }
            }
        }

        usort($columns, fn (array $a, array $b) => [$a['table'], $a['column']] <=> [$b['table'], $b['column']]);

        return $columns;
    }

    /**
     * Rewrite a column in batches, straight in the table (no model events, no timestamps).
     *
     * @param  Closure(string): ?string  $convert  the stored value => its replacement, or null to leave it
     * @return int rows changed
     */
    public static function rewrite(string $table, string $primaryKey, string $column, Closure $convert, int $batch = 200): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $changed = 0;

        DB::table($table)->select([$primaryKey, $column])->whereNotNull($column)
            ->chunkById($batch, function ($rows) use ($table, $primaryKey, $column, $convert, &$changed) {
                DB::transaction(function () use ($rows, $table, $primaryKey, $column, $convert, &$changed) {
                    foreach ($rows as $row) {
                        $value = $convert((string) $row->{$column});

                        if ($value !== null) {
                            DB::table($table)->where($primaryKey, $row->{$primaryKey})->update([$column => $value]);
                            $changed++;
                        }
                    }
                });
            }, $primaryKey);

        return $changed;
    }
}
