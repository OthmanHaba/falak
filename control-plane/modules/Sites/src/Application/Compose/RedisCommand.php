<?php

namespace Falak\Sites\Application\Compose;

/**
 * The settings a compose Redis / Valkey service sets on its command line, for the Falak instance replacing it:
 * `--maxmemory <n>[kb|mb|gb|k|m|g]` (whole MB, at least 16), `--maxmemory-policy <policy>` and `--appendonly yes`
 * (aof). Only these flags, in `redis-server …` / `valkey-server …` commands given as a string or a list; anything
 * else (a config file, shell wrappers, variables) is ignored and the instance gets Falak's defaults.
 */
final class RedisCommand
{
    private const POLICIES = ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl'];

    /**
     * @return array{maxmemory_mb?: int, eviction?: string, persistence?: string}
     */
    public static function settings(mixed $command): array
    {
        $tokens = match (true) {
            is_string($command) => preg_split('/\s+/', trim($command)) ?: [],
            is_array($command) && array_is_list($command) => array_map(fn ($t) => is_scalar($t) ? (string) $t : '', $command),
            default => [],
        };

        if ($tokens === [] || preg_match('#(^|/)(redis|valkey)-server$#', (string) $tokens[0]) !== 1 && ! str_starts_with((string) $tokens[0], '--')) {
            return [];
        }

        $settings = [];

        foreach ($tokens as $i => $token) {
            $value = strtolower(trim((string) ($tokens[$i + 1] ?? ''), '"\''));

            match (strtolower($token)) {
                '--maxmemory' => ($mb = self::megabytes($value)) !== null ? $settings['maxmemory_mb'] = $mb : null,
                '--maxmemory-policy' => in_array($value, self::POLICIES, true) ? $settings['eviction'] = $value : null,
                '--appendonly' => $value === 'yes' ? $settings['persistence'] = 'aof' : null,
                default => null,
            };
        }

        return $settings;
    }

    private static function megabytes(string $value): ?int
    {
        if (preg_match('/^(\d+)(b|k|kb|m|mb|g|gb)?$/', $value, $m) !== 1) {
            return null;
        }

        $bytes = (int) $m[1] * match ($m[2] ?? '') {
            'k' => 1000, 'kb' => 1024,
            'm' => 1000 ** 2, 'mb' => 1024 ** 2,
            'g' => 1000 ** 3, 'gb' => 1024 ** 3,
            default => 1,
        };

        // 0 = no limit in Redis: Falak's default (bounded by the server's RAM) applies instead.
        return $bytes === 0 ? null : max(16, intdiv($bytes, 1024 ** 2));
    }
}
