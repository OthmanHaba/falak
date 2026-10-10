<?php

namespace Falak\Secrets\Infrastructure\Providers\Drivers;

use Falak\Secrets\Infrastructure\Providers\ProviderFailure;

/**
 * Turning what a provider returned into an environment variable value.
 */
final class Values
{
    /** Strings as they are, numbers and booleans as JSON; anything else is refused. */
    public static function scalar(mixed $value, string $display): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) json_encode($value);
        }

        throw new ProviderFailure("{$display} is not a single value (an object, a list or null); point the reference at one field.");
    }

    /** A key of a JSON object value (AWS Secrets Manager's `#key`). */
    public static function jsonKey(string $json, string $key, string $display): string
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! array_key_exists($key, $data)) {
            throw new ProviderFailure("{$display}: the secret is not a JSON object with the key \"{$key}\".");
        }

        return self::scalar($data[$key], $display);
    }
}
