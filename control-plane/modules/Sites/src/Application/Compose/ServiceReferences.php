<?php

namespace Kiln\Sites\Application\Compose;

use Kiln\Sites\Contracts\Data\ComposeRewrites;

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
 * - cache (a Redis / Valkey service becoming a Kiln instance): `redis://[…@]<service>[:port]` anywhere in a value →
 *   REDIS_URL (credentials included; a database path like `/1` is kept), `<service>:<port>` anywhere in a value →
 *   REDIS_HOST:REDIS_PORT, the bare name under a host-like key → REDIS_HOST; in such a group REDIS_/VALKEY_ prefixed
 *   …PORT and …PASS(WORD) → REDIS_PORT, REDIS_PASSWORD, and a REDIS_HOST without REDIS_PORT / REDIS_PASSWORD next to it
 *   gains them (instances listen on 6380+ and always have a password; clients default to 6379 and none).
 */
final class ServiceReferences
{
    private const DB_PREFIX = '/^(DB|DATABASE|POSTGRES|POSTGRESQL|PG|MYSQL|MARIADB)_?/i';

    private const CACHE_PREFIX = '/^(REDIS|VALKEY)_?/i';

    /**
     * @param  array<string, mixed>  $document  the parsed compose file
     * @param  'database'|'cache'|'site'  $mode
     * @param  array<string, string>  $stackVariables  the stack's own variables (its `.env`)
     * @return array<string, array<string, string>> group (a remaining service, or ComposeRewrites::STACK) => variable
     *                                              => template; each group is detected on its own, so the same name in
     *                                              two services can point at different things
     */
    public static function find(array $document, string $service, string $mode, array $stackVariables = []): array
    {
        $groups = [];

        foreach ((array) ($document['services'] ?? []) as $name => $definition) {
            if ((string) $name !== $service && is_array($definition)) {
                $groups[(string) $name] = self::environment($definition['environment'] ?? []);
            }
        }

        $groups[ComposeRewrites::STACK] = array_map('strval', $stackVariables);
        $found = [];

        foreach ($groups as $group => $variables) {
            if (($templates = self::inGroup($variables, $service, $mode)) !== []) {
                ksort($templates);
                $found[$group] = $templates;
            }
        }

        return $found;
    }

    /**
     * The other services of the stack that $service uses: its `depends_on`, and the services its `environment:` points
     * at (a URL host, or a bare name under a host-like key / with a port). Those are what it can no longer reach once
     * it runs outside the stack's network. The environment is read as the service gets it: `${VAR}` filled in from the
     * stack's variables.
     *
     * @param  array<string, mixed>  $document  the parsed compose file
     * @param  array<string, string>  $stackVariables  the stack's own variables (its `.env`)
     * @return list<string>
     */
    public static function uses(array $document, string $service, array $stackVariables = []): array
    {
        $services = array_map('strval', array_keys((array) ($document['services'] ?? [])));
        $definition = $document['services'][$service] ?? null;

        if (! is_array($definition)) {
            return [];
        }

        $dependsOn = $definition['depends_on'] ?? [];
        $used = array_map('strval', is_array($dependsOn) ? (array_is_list($dependsOn) ? $dependsOn : array_keys($dependsOn)) : []);
        $stackVariables = array_map('strval', $stackVariables);
        $environment = array_map(fn (string $value) => ComposeInterpolation::apply($value, $stackVariables), self::environment($definition['environment'] ?? []));

        foreach ($services as $other) {
            foreach ($environment as $key => $value) {
                if ($other !== $service && self::template($key, trim($value), $other, 'site') !== null) {
                    $used[] = $other;
                    break;
                }
            }
        }

        $used = array_values(array_unique(array_filter($used, fn (string $name) => $name !== $service && in_array($name, $services, true))));
        sort($used);

        return $used;
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

        if ($found !== [] && $mode === 'cache') {
            return self::cacheCompanions($variables, $found);
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

    /**
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $found
     * @return array<string, string>
     */
    private static function cacheCompanions(array $variables, array $found): array
    {
        foreach ($variables as $key => $value) {
            if (isset($found[$key]) || preg_match(self::CACHE_PREFIX, $key) !== 1) {
                continue;
            }

            $name = strtoupper($key);
            $reference = match (true) {
                str_ends_with($name, 'PORT') => 'REDIS_PORT',
                preg_match('/(PASS|PASSWORD)$/', $name) === 1 => 'REDIS_PASSWORD',
                default => null,
            };

            if ($reference !== null) {
                $found[$key] = '{ref:'.$reference.'}';
            }
        }

        // Laravel-style clients default to port 6379 and no password: a REDIS_HOST gains the instance's port and password.
        foreach (['REDIS_PORT', 'REDIS_PASSWORD'] as $key) {
            if (isset($found['REDIS_HOST']) && ! array_key_exists($key, $variables)) {
                $found[$key] = '{ref:'.$key.'}';
            }
        }

        return $found;
    }

    /** A Redis / Valkey service inside a value: its URLs, then `<service>:<port>`, then the bare name under a host key. */
    private static function cacheTemplate(string $key, string $value, string $service): ?string
    {
        $host = preg_quote($service, '#');
        $out = (string) preg_replace("#\b(?:redis|valkey)://(?:[^@/\s]*@)?{$host}(?::\d+)?(?=[/?\s,;\"']|$)#i", '{ref:REDIS_URL}', $value, -1, $urls);
        $out = (string) preg_replace("#(?<![\w.@-]){$host}:\d+(?![\w.])#", '{ref:REDIS_HOST}:{ref:REDIS_PORT}', $out, -1, $pairs);

        if ($urls + $pairs > 0) {
            return $out;
        }

        return $value === $service && preg_match('/(HOST|HOSTNAME|ADDR|ADDRESS|SERVER|ENDPOINT)$/i', $key) === 1 ? '{ref:REDIS_HOST}' : null;
    }

    private static function template(string $key, string $value, string $service, string $mode): ?string
    {
        if ($mode === 'cache') {
            return self::cacheTemplate($key, $value, $service);
        }

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
