<?php

namespace Kiln\Sites\Application\Compose;

/**
 * Which variables of a compose stack point at one of its services (the service's name is its hostname on the
 * stack's network), and what they become once that service runs as a Kiln service. Templates use placeholders filled
 * in at render time (EloquentComposeServiceExtraction::rewrites): `{ref:KEY}` → `${{ <database>.KEY }}`, `{url}` /
 * `{host}` → the split-out site's address.
 *
 * Detected, per service of the stack (its `environment:`) and in the stack's own variables:
 * - a URL whose host is the service (`postgres://u:p@db:5432/app`, `http://api:8000/v1`): database → DATABASE_URL,
 *   site → its URL plus the path;
 * - the bare hostname, optionally with a port (`DB_HOST=db`, `API=api:8000`): database → DB_HOST, site → its host;
 * - database only: in a group that points at the database, its companion keys (DB_/DATABASE_/POSTGRES_/PG/MYSQL_/
 *   MARIADB_ prefixed …PORT, …USER(NAME), …PASS(WORD), …DB/DATABASE/NAME) → DB_PORT, DB_USERNAME, DB_PASSWORD,
 *   DB_DATABASE.
 */
final class ServiceReferences
{
    private const DB_PREFIX = '/^(DB|DATABASE|POSTGRES|POSTGRESQL|PG|MYSQL|MARIADB)_?/i';

    /**
     * @param  array<string, mixed>  $document  the parsed compose file
     * @param  'database'|'site'  $mode
     * @param  array<string, string>  $stackVariables  the stack's own variables (its `.env`)
     * @return array<string, string> variable => template (sorted by name; the first service pointing at it wins)
     */
    public static function find(array $document, string $service, string $mode, array $stackVariables = []): array
    {
        $groups = [];

        foreach ((array) ($document['services'] ?? []) as $name => $definition) {
            if ((string) $name !== $service && is_array($definition)) {
                $groups[] = self::environment($definition['environment'] ?? []);
            }
        }

        $groups[] = array_map('strval', $stackVariables);
        $found = [];

        foreach ($groups as $variables) {
            foreach (self::inGroup($variables, $service, $mode) as $key => $template) {
                $found[$key] ??= $template;
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * A service's `environment:` (map or `KEY=value` list) as strings; keys without a value are left out.
     *
     * @return array<string, string>
     */
    public static function environment(mixed $environment): array
    {
        $variables = [];

        foreach ((array) $environment as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value) || ! str_contains($value, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $value, 2);
            }

            if ($value === null || is_array($value)) {
                continue;
            }

            $variables[trim((string) $key)] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $variables;
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private static function inGroup(array $variables, string $service, string $mode): array
    {
        $found = [];

        foreach ($variables as $key => $value) {
            if (($template = self::template($key, trim($value), $service, $mode)) !== null) {
                $found[$key] = $template;
            }
        }

        if ($found === [] || $mode !== 'database') {
            return $found;
        }

        foreach ($variables as $key => $value) {
            if (isset($found[$key]) || preg_match(self::DB_PREFIX, $key) !== 1) {
                continue;
            }

            $name = strtoupper($key);
            $reference = match (true) {
                str_ends_with($name, 'PORT') => 'DB_PORT',
                preg_match('/(USER|USERNAME)$/', $name) === 1 => 'DB_USERNAME',
                preg_match('/(PASS|PASSWORD|PWD)$/', $name) === 1 => 'DB_PASSWORD',
                preg_match('/(DB|DATABASE|NAME|DBNAME)$/', $name) === 1 => 'DB_DATABASE',
                default => null,
            };

            if ($reference !== null) {
                $found[$key] = '{ref:'.$reference.'}';
            }
        }

        return $found;
    }

    private static function template(string $key, string $value, string $service, string $mode): ?string
    {
        $host = preg_quote($service, '#');

        if (preg_match("#^[a-z][a-z0-9+.\-]*://(?:[^@/\s]*@)?{$host}(?::\d+)?(/\S*)?$#i", $value, $match) === 1) {
            return $mode === 'database' ? '{ref:DATABASE_URL}' : '{url}'.($match[1] ?? '');
        }

        // A bare name is a host only under a host-like key or with a port: DB_CONNECTION=mysql is Laravel's driver,
        // not the `mysql` service.
        if (preg_match("#^{$host}(:\d+)?$#", $value, $match) === 1
            && (isset($match[1]) || preg_match('/(HOST|HOSTNAME|ADDR|ADDRESS|SERVER|ENDPOINT)$/i', $key) === 1)) {
            return match (true) {
                $mode === 'site' => '{host}',
                isset($match[1]) => '{ref:DB_HOST}:{ref:DB_PORT}',
                default => '{ref:DB_HOST}',
            };
        }

        return null;
    }
}
