<?php

namespace Kiln\Network\Infrastructure;

/**
 * Stable JSON (object keys sorted recursively, lists kept in order) for desired-state hashing.
 */
final class CanonicalJson
{
    /**
     * @param  array<array-key, mixed>  $document
     */
    public static function encode(array $document): string
    {
        return json_encode(self::normalize($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param  array<array-key, mixed>  $document
     */
    public static function hash(array $document): string
    {
        return hash('sha256', self::encode($document));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}
