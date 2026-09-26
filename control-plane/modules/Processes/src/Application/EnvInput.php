<?php

namespace Kiln\Processes\Application;

/**
 * Environment variables edited as a list of {key, value} rows. Values are never sent back to the UI,
 * so a row without a value keeps the stored one.
 */
final class EnvInput
{
    /**
     * @param  list<array{key: string, value?: ?string}>  $rows
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    public static function merge(array $rows, array $current): array
    {
        $env = [];

        foreach ($rows as $row) {
            $key = trim((string) $row['key']);
            $value = $row['value'] ?? null;

            if ($value === null) {
                if (array_key_exists($key, $current)) {
                    $env[$key] = $current[$key];
                }

                continue;
            }

            $env[$key] = (string) $value;
        }

        ksort($env);

        return $env;
    }
}
