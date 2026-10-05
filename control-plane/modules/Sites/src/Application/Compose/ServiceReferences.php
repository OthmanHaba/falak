<?php

namespace Falak\Sites\Application\Compose;

use Falak\Sites\Contracts\Data\ComposeRewrites;

/**
 * Which variables of a compose stack point at one of its services (the service's name is its hostname on the
 * stack's network), and what they become once that service runs as a Falak service. Templates use placeholders filled
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
 * - cache (a Redis / Valkey service becoming a Falak instance): `redis://[…@]<service>[:port]` anywhere in a value →
 *   REDIS_URL (credentials included; a database path like `/1` is kept), `<service>:<port>` → REDIS_HOST:REDIS_PORT
 *   where it is an address (after `//` or `@`, or a whole item of the value under a host-like key or with a port of
 *   1024+: `IMAGE=redis:7` is not one), the bare name under a host-like key → REDIS_HOST; REDIS_/VALKEY_ prefixed
 *   …PORT and …PASS(WORD) → REDIS_PORT, REDIS_PASSWORD when a key of their own prefix (REDIS_QUEUE_PORT:
 *   REDIS_QUEUE_HOST / _URL …) points at the service, and a REDIS_HOST without REDIS_PORT / REDIS_PASSWORD next to it
 *   gains them (instances listen on 6380+ and always have a password; clients default to 6379 and none).
 *   `rediss://` / `valkeys://` (TLS) values are left alone (Falak instances have no TLS): tlsReferences() lists them.
 */
final class ServiceReferences
{
    private const DB_PREFIX = '/^(DB|DATABASE|POSTGRES|POSTGRESQL|PG|MYSQL|MARIADB)_?/i';

    private const CACHE_PREFIX = '/^(REDIS|VALKEY)_?/i';

    private const HOST_KEY = '/(HOST|HOSTNAME|HOSTS|ADDR|ADDRESS|ADDRS|ADDRESSES|SERVER|SERVERS|ENDPOINT|ENDPOINTS|NODES|URL|URI|DSN)$/i';

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
        $others = array_values(array_diff(array_map('strval', array_keys((array) ($document['services'] ?? []))), [$service]));
        $found = [];

        foreach ($groups as $group => $variables) {
            if (($templates = self::inGroup($variables, $service, $mode, $others)) !== []) {
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
     * @param  list<string>  $others  the stack's other services
     * @return array<string, string>
     */
    private static function inGroup(array $variables, string $service, string $mode, array $others = []): array
    {
        $found = [];

        foreach ($variables as $key => $value) {
            if (($template = self::template($key, trim($value), $service, $mode)) !== null) {
                $found[$key] = $template;
            }
        }

        if ($found !== [] && $mode === 'cache') {
            return self::cacheCompanions($variables, $found, $service, $others)['found'];
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
     * The companions (REDIS_ / VALKEY_ …PORT, …PASS(WORD)) of the keys that point at the service: those of their own
     * prefix (REDIS_QUEUE_PORT belongs to REDIS_QUEUE_HOST). A companion whose prefix has no host key at all
     * (REDIS_PORT next to QUEUE_HOST: cache) is taken too when nothing in the group points at another service of the
     * stack — it can only be this one's; otherwise it is left and reported (`orphans`). A group that also reaches the
     * service over TLS (left alone) keeps its companions.
     *
     * @param  array<string, string>  $variables
     * @param  array<string, string>  $found
     * @param  list<string>  $others  the stack's other services
     * @return array{found: array<string, string>, orphans: list<string>}
     */
    private static function cacheCompanions(array $variables, array $found, string $service, array $others = []): array
    {
        $tls = array_map(self::cacheGroup(...), array_keys(array_filter($variables, fn (string $value) => self::cacheTls(trim($value), $service))));
        $groups = array_diff(array_map(self::cacheGroup(...), array_keys($found)), $tls);
        // Prefixes that name a host of their own (REDIS_HOST=queue: its REDIS_PORT is queue's).
        $hosted = array_map(self::cacheGroup(...), array_keys(array_filter($variables, fn (string $value, string $key) => preg_match(self::HOST_KEY, $key) === 1, ARRAY_FILTER_USE_BOTH)));
        $elsewhere = $tls !== [] || array_filter($variables, function (string $value, string $key) use ($others) {
            foreach ($others as $other) {
                if (self::cacheTemplate($key, trim($value), $other) !== null) {
                    return true;
                }
            }

            return false;
        }, ARRAY_FILTER_USE_BOTH) !== [];
        $orphans = [];

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
            $group = self::cacheGroup($key);

            if ($reference === null || (! in_array($group, $groups, true) && in_array($group, $hosted, true))) {
                continue;
            }

            if (in_array($group, $groups, true) || ! $elsewhere) {
                $found[$key] = '{ref:'.$reference.'}';
            } else {
                $orphans[] = $key;
            }
        }

        // Laravel-style clients default to port 6379 and no password: a REDIS_HOST gains the instance's port and password.
        foreach (['REDIS_PORT', 'REDIS_PASSWORD'] as $key) {
            if (isset($found['REDIS_HOST']) && ! array_key_exists($key, $variables)) {
                $found[$key] = '{ref:'.$key.'}';
            }
        }

        return ['found' => $found, 'orphans' => $orphans];
    }

    /**
     * Companions left alone although their group points at the service (see cacheCompanions): the group also points at
     * another service of the stack, so whose port / password they are is unclear.
     *
     * @param  array<string, mixed>  $document  the parsed compose file
     * @param  array<string, string>  $stackVariables  the stack's own variables (its `.env`)
     * @return list<string> "KEY (group)"
     */
    public static function unclearCompanions(array $document, string $service, array $stackVariables = []): array
    {
        $out = [];

        foreach (self::groups($document, $service, $stackVariables) as $group => $variables) {
            $found = [];

            foreach ($variables as $key => $value) {
                if (($template = self::cacheTemplate($key, trim($value), $service)) !== null) {
                    $found[$key] = $template;
                }
            }

            if ($found === []) {
                continue;
            }

            $others = array_values(array_diff(array_map('strval', array_keys((array) ($document['services'] ?? []))), [$service]));

            foreach (self::cacheCompanions($variables, $found, $service, $others)['orphans'] as $key) {
                $out[] = "{$key} ({$group})";
            }
        }

        return $out;
    }

    /**
     * The other services whose healthcheck command names $service as a host (a word of its own: not `redis-cli`,
     * `redis-server` or `myredis`).
     *
     * @param  array<string, mixed>  $document  the parsed compose file
     * @return list<string>
     */
    public static function healthchecksNaming(array $document, string $service): array
    {
        $host = preg_quote($service, '#');
        $out = [];

        foreach ((array) ($document['services'] ?? []) as $name => $definition) {
            $test = is_array($definition) ? ($definition['healthcheck']['test'] ?? null) : null;
            $command = is_array($test) ? implode(' ', array_map('strval', array_filter($test, 'is_scalar'))) : (is_string($test) ? $test : '');

            if ((string) $name !== $service && preg_match("#(?<![\w.-]){$host}(?![\w.-])#", $command) === 1) {
                $out[] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * The environment of each other service, and the stack's variables (labelled for messages).
     *
     * @param  array<string, mixed>  $document
     * @param  array<string, string>  $stackVariables
     * @return array<string, array<string, string>>
     */
    private static function groups(array $document, string $service, array $stackVariables): array
    {
        $groups = [];

        foreach ((array) ($document['services'] ?? []) as $name => $definition) {
            if ((string) $name !== $service && is_array($definition)) {
                $groups[(string) $name] = self::environment($definition['environment'] ?? []);
            }
        }

        $groups['the stack\'s variables'] = array_map('strval', $stackVariables);

        return $groups;
    }

    /**
     * The variables that reach the Redis / Valkey service over TLS (`rediss://`, `valkeys://`): a Falak instance has no
     * TLS, so they keep pointing at the service and need changing by hand.
     *
     * @param  array<string, mixed>  $document  the parsed compose file
     * @param  array<string, string>  $stackVariables  the stack's own variables (its `.env`)
     * @return list<string> "KEY (group)"
     */
    public static function tlsReferences(array $document, string $service, array $stackVariables = []): array
    {
        $found = [];

        foreach (self::groups($document, $service, $stackVariables) as $group => $variables) {
            foreach ($variables as $key => $value) {
                if (self::cacheTls(trim($value), $service)) {
                    $found[] = "{$key} ({$group})";
                }
            }
        }

        return $found;
    }

    private static function cacheTls(string $value, string $service): bool
    {
        $host = preg_quote($service, '#');

        return preg_match("#\b(?:rediss|valkeys)://(?:[^@/\s]*@)?{$host}(?::\d+)?(?=[/?\s,;\"']|$)#i", $value) === 1;
    }

    /** REDIS_QUEUE_PORT, REDIS_QUEUE_HOST → REDIS_QUEUE; VALKEY_PASSWORD, REDIS_URL → REDIS. */
    private static function cacheGroup(string $key): string
    {
        $name = (string) preg_replace('/^VALKEY/', 'REDIS', strtoupper($key));

        return (string) preg_replace('/_?(HOST|HOSTNAME|HOSTS|ADDR|ADDRESS|ADDRS|ADDRESSES|SERVER|SERVERS|ENDPOINT|ENDPOINTS|NODES|URL|URI|DSN|PORT|PASS|PASSWORD|PWD|USER|USERNAME)$/', '', $name);
    }

    /**
     * A Redis / Valkey service inside a value: its URLs, then `<service>:<port>` where it is an address, then the bare
     * name under a host key. A TLS URL of the service leaves the whole value alone.
     */
    private static function cacheTemplate(string $key, string $value, string $service): ?string
    {
        if (self::cacheTls($value, $service)) {
            return null;
        }

        $host = preg_quote($service, '#');
        $out = (string) preg_replace("#\b(?:redis|valkey)://(?:[^@/\s]*@)?{$host}(?::\d+)?(?=[/?\s,;\"']|$)#i", '{ref:REDIS_URL}', $value, -1, $urls);
        $hostKey = preg_match(self::HOST_KEY, $key) === 1;
        $pairs = 0;
        $subject = $out;
        $out = (string) preg_replace_callback("#(?<![\w.-]){$host}:(\d+)(?![\w.:-])#", function (array $m) use ($hostKey, $subject, &$pairs): string {
            [$text, $offset] = $m[0];
            $before = substr($subject, 0, $offset);
            // An address: in a URL's authority (tcp://redis:6379, user@redis:6379), or a whole item of the value
            // (redis:6379, a,redis:6379) under a host-like key or with a non-privileged port — not an image (redis:7).
            $address = str_ends_with($before, '//') || str_ends_with($before, '@')
                || (($before === '' || preg_match('/[\s,;]$/', $before) === 1) && ($hostKey || (int) $m[1][0] >= 1024));

            if (! $address) {
                return $text;
            }
            $pairs++;

            return '{ref:REDIS_HOST}:{ref:REDIS_PORT}';
        }, $out, -1, $count, PREG_OFFSET_CAPTURE);

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
