<?php

namespace Kiln\Telemetry\Contracts;

/**
 * Helpers for building PromQL / LogQL / TraceQL safely from untrusted values.
 */
final class PromQl
{
    /** Escape a value for use inside a double-quoted PromQL/LogQL/TraceQL string literal. */
    public static function quote(string $value): string
    {
        return '"'.addcslashes($value, "\"\\\n\r\t").'"';
    }

    /** `name="value"` matcher with the value escaped. */
    public static function label(string $name, string $value): string
    {
        if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid label name [{$name}].");
        }

        return $name.'='.self::quote($value);
    }
}
