<?php

namespace Kiln\Sites\Application\Compose;

/**
 * Compose interpolation with a stack's variables: `${VAR}`, `${VAR:-default}` / `${VAR-default}` and `$VAR`; unknown
 * variables without a default stay as written.
 */
final class ComposeInterpolation
{
    /** @param  array<string, string>  $variables */
    public static function apply(string $value, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_]*)(?::?-([^}]*))?\}|\$([A-Za-z_][A-Za-z0-9_]*)/',
            function (array $m) use ($variables) {
                if (($m[3] ?? '') !== '') {
                    return $variables[$m[3]] ?? $m[0];
                }

                return $variables[$m[1]] ?? (($m[2] ?? '') !== '' ? $m[2] : $m[0]);
            },
            $value,
        );
    }
}
