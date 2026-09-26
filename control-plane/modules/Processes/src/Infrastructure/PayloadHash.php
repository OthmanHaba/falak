<?php

namespace Kiln\Processes\Infrastructure;

/**
 * sha256 of a payload's canonical JSON (object keys sorted recursively, lists kept in order).
 */
final class PayloadHash
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function of(array $payload): string
    {
        return hash('sha256', json_encode(self::canonical($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }
}
