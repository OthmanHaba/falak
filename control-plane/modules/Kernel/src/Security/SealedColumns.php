<?php

namespace Falak\Kernel\Security;

use Closure;
use Falak\Kernel\Security\Casts\Sealed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;

/**
 * Every column sealed by a model cast (Sealed / SealedArray), found by reading the casts of the models in
 * modules/<Module>/src/Domain/Models, and batch re-sealing of a column (data-key rotation).
 */
class SealedColumns
{
    /**
     * @return list<array{table: string, primary_key: string, column: string}>
     */
    public function all(): array
    {
        $columns = [];

        foreach (glob(base_path('modules/*/src/Domain/Models/*.php')) ?: [] as $file) {
            $class = 'Falak\\'.basename(dirname($file, 4)).'\\Domain\\Models\\'.basename($file, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var Model $model */
            $model = new $class;

            foreach ($model->getCasts() as $column => $cast) {
                if (is_string($cast) && is_a(explode(':', $cast, 2)[0], Sealed::class, true)) {
                    $columns[] = ['table' => $model->getTable(), 'primary_key' => $model->getKeyName(), 'column' => $column];
                }
            }
        }

        usort($columns, fn (array $a, array $b) => [$a['table'], $a['column']] <=> [$b['table'], $b['column']]);

        return $columns;
    }

    /**
     * Rewrite a column in batches, straight in the table (no model events, no timestamps).
     *
     * @param  Closure(string, string): ?string  $convert  (stored value, the row's primary key) => its replacement,
     *                                                     or null to leave it
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
                        $value = $convert((string) $row->{$column}, (string) $row->{$primaryKey});

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
