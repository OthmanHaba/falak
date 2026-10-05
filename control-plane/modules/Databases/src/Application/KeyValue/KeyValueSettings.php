<?php

namespace Falak\Databases\Application\KeyValue;

use Falak\Databases\Domain\Models\Database;
use Falak\Fleet\Contracts\AgentDirectory;
use Illuminate\Validation\ValidationException;

/**
 * Settings of a Redis / Valkey instance (maxmemory_mb, eviction, persistence): defaults, validation, and the memory
 * limit bounded by the server's RAM (three quarters of it, as reported by the agent).
 */
final class KeyValueSettings
{
    public const MIN_MEMORY_MB = 16;

    public function __construct(private readonly AgentDirectory $agents) {}

    /**
     * @return array{maxmemory_mb: int, eviction: string, persistence: string}
     */
    public static function defaults(): array
    {
        return [
            'maxmemory_mb' => (int) config('databases.key_value.maxmemory_mb', 128),
            'eviction' => (string) config('databases.key_value.eviction', 'noeviction'),
            'persistence' => (string) config('databases.key_value.persistence', 'rdb'),
        ];
    }

    /**
     * @return array{maxmemory_mb: int, eviction: string, persistence: string}
     */
    public static function of(Database $database): array
    {
        $settings = array_merge(self::defaults(), array_filter((array) $database->settings, fn ($value) => $value !== null));

        return ['maxmemory_mb' => (int) $settings['maxmemory_mb'], 'eviction' => (string) $settings['eviction'], 'persistence' => (string) $settings['persistence']];
    }

    /** Highest memory limit a new or updated instance may get on the server (null when the RAM is unknown). */
    public function maxMemoryMb(string $serverId): ?int
    {
        $bytes = (int) ($this->agents->forServer($serverId)?->facts['memory_bytes'] ?? 0);

        return $bytes > 0 ? max(self::MIN_MEMORY_MB, intdiv(intdiv($bytes, 1024 * 1024) * 3, 4)) : null;
    }

    /**
     * Merges $input over $current and validates the result.
     *
     * @param  array{maxmemory_mb?: ?int, eviction?: ?string, persistence?: ?string}  $input
     * @param  array{maxmemory_mb: int, eviction: string, persistence: string}  $current
     * @return array{maxmemory_mb: int, eviction: string, persistence: string}
     *
     * @throws ValidationException
     */
    public function resolve(string $serverId, array $input, array $current, bool $capDefault = false): array
    {
        $settings = $current;

        foreach (['maxmemory_mb', 'eviction', 'persistence'] as $key) {
            if (($input[$key] ?? null) !== null && $input[$key] !== '') {
                $settings[$key] = $key === 'maxmemory_mb' ? (int) $input[$key] : (string) $input[$key];
            }
        }

        $max = $this->maxMemoryMb($serverId);

        // The default limit fits small servers instead of failing on them.
        if ($capDefault && $max !== null && ($input['maxmemory_mb'] ?? null) === null) {
            $settings['maxmemory_mb'] = min($settings['maxmemory_mb'], $max);
        }

        if ($settings['maxmemory_mb'] < self::MIN_MEMORY_MB || ($max !== null && $settings['maxmemory_mb'] > $max)) {
            throw ValidationException::withMessages(['maxmemory_mb' => $max !== null
                ? 'The memory limit must be between '.self::MIN_MEMORY_MB." and {$max} MB on this server."
                : 'The memory limit must be at least '.self::MIN_MEMORY_MB.' MB.']);
        }

        if (! in_array($settings['eviction'], (array) config('databases.key_value.evictions', []), true)) {
            throw ValidationException::withMessages(['eviction' => 'Unknown eviction policy.']);
        }

        if (! in_array($settings['persistence'], (array) config('databases.key_value.persistences', []), true)) {
            throw ValidationException::withMessages(['persistence' => 'Choose rdb, aof or none.']);
        }

        return $settings;
    }
}
