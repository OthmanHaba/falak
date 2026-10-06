<?php

namespace Falak\Sites\Infrastructure;

use Falak\Projects\Contracts\VariableReferences;
use Falak\Sites\Contracts\SecretVariables;

/**
 * Secret by name (`sites.secret_variables`), or by referencing a secret of another service (`${{ db.DB_PASSWORD }}`),
 * until the secret store flags secrets itself.
 */
final class PatternSecretVariables implements SecretVariables
{
    public function __construct(private readonly VariableReferences $references) {}

    public function names(array $variables): array
    {
        $names = [];

        foreach ($variables as $name => $value) {
            if (self::secretName((string) $name)) {
                $names[(string) $name] = true;
            }
        }

        foreach ($this->references->referencesIn(array_map('strval', $variables)) as $reference) {
            if (self::secretName($reference['key'])) {
                $names[$reference['variable']] = true;
            }
        }

        return array_keys($names);
    }

    public static function secretName(string $name): bool
    {
        $name = strtoupper($name);
        $config = (array) config('sites.secret_variables', []);

        foreach ((array) ($config['except'] ?? []) as $pattern) {
            if (preg_match((string) $pattern, $name) === 1) {
                return false;
            }
        }

        foreach ((array) ($config['patterns'] ?? []) as $pattern) {
            if (preg_match((string) $pattern, $name) === 1) {
                return true;
            }
        }

        return false;
    }
}
