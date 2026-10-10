<?php

namespace Falak\Sites\Infrastructure;

use Falak\Projects\Contracts\VariableReferences;
use Falak\Sites\Contracts\SecretVariables;

/**
 * Secret by name (`sites.secret_variables`), by a credentials URL value, by referencing the secret store
 * (`${{ secrets.STRIPE_KEY }}`), or by referencing a secret of another service (`${{ db.DB_PASSWORD }}`).
 */
final class PatternSecretVariables implements SecretVariables
{
    public function __construct(private readonly VariableReferences $references) {}

    public function names(array $variables): array
    {
        $names = [];

        foreach ($variables as $name => $value) {
            if (self::secretName((string) $name) || self::secretValue((string) $value)) {
                $names[(string) $name] = true;
            }
        }

        foreach ($this->references->referencesIn(array_map('strval', $variables)) as $reference) {
            if ($reference['service'] === 'secrets' || self::secretName($reference['key'])) {
                $names[$reference['variable']] = true;
            }
        }

        return array_keys($names);
    }

    public static function secretName(string $name): bool
    {
        $name = strtoupper($name);
        $config = (array) config('sites.secret_variables', []);
        $matches = fn (string $key) => array_filter((array) ($config[$key] ?? []), fn ($pattern) => preg_match((string) $pattern, $name) === 1) !== [];

        if ($matches('always')) {
            return true;
        }

        if ($matches('except')) {
            return false;
        }

        return $matches('patterns');
    }

    /**
     * A URL with credentials (postgres://app:pw@db/app, https://user:token@host): secret whatever its name.
     */
    public static function secretValue(string $value): bool
    {
        return preg_match('#^[a-z][a-z0-9+.\-]*://[^/\s:@]+:[^/\s@]+@#i', $value) === 1;
    }
}
