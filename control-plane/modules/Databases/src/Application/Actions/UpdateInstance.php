<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes an instance's limits, settings and access, then converges its container (db.instance.update: a new memory
 * limit or settings re-render the config and restart it; the data stays on its volume).
 */
final class UpdateInstance
{
    public const EVICTIONS = ['noeviction', 'allkeys-lru', 'allkeys-lfu', 'allkeys-random', 'volatile-lru', 'volatile-lfu', 'volatile-random', 'volatile-ttl'];

    public const PERSISTENCES = ['rdb', 'aof', 'none'];

    public function __construct(
        private readonly ApplyInstance $apply,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{memory_mb?: ?int, cpus?: ?float, settings?: ?array<string, mixed>, public_access?: ?bool, require_tls?: ?bool, pitr_enabled?: ?bool}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $instance, array $data, ?string $actorId = null): DatabaseInstance
    {
        if ($instance->status !== InstanceStatus::Active && $instance->status !== InstanceStatus::Failed) {
            throw ValidationException::withMessages(['instance' => "The database is {$instance->status->value}."]);
        }

        $changes = [];

        if (array_key_exists('memory_mb', $data) && $data['memory_mb'] !== null) {
            $memory = (int) $data['memory_mb'] * 1024 ** 2;
            CreateInstance::assertMemory($instance->engine, $memory);
            $changes['memory_bytes'] = $memory;
        }

        if (array_key_exists('cpus', $data)) {
            $changes['cpus'] = $data['cpus'] !== null ? round((float) $data['cpus'], 2) : null;
        }

        if (array_key_exists('settings', $data) && $data['settings'] !== null) {
            $settings = self::settings($instance->engine, [...(array) $instance->settings, ...(array) $data['settings']]);
            $changes['settings'] = $settings !== [] ? $settings : null;
        }

        foreach (['public_access', 'require_tls', 'pitr_enabled'] as $flag) {
            if (array_key_exists($flag, $data) && $data[$flag] !== null) {
                $changes[$flag] = (bool) $data[$flag];
            }
        }

        // Public access never goes without TLS.
        if (($changes['public_access'] ?? $instance->public_access) && ! ($changes['require_tls'] ?? $instance->require_tls)) {
            $changes['require_tls'] = true;
        }

        DB::transaction(function () use ($instance, $changes) {
            $instance->forceFill($changes)->save();
            ($this->apply)($instance);
        });

        $this->audit->record('databases.instance_updated', 'database_instance', $instance->id, array_diff_key($changes, ['settings' => true]) + ['settings' => array_key_exists('settings', $changes)], $instance->organization_id);

        return $instance;
    }

    /**
     * falak-db settings an instance accepts (docs/DB_IMAGES.md "Settings"); `tls` stays on, `require_tls` follows the
     * instance's flag.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, int|string>
     *
     * @throws ValidationException
     */
    public static function settings(Engine $engine, array $settings): array
    {
        $out = [];

        foreach ($settings as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $valid = match (true) {
                $key === 'max_connections' && ! $engine->isKeyValue() => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 10000]]) !== false,
                $key === 'slow_query_ms' => filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 600000]]) !== false,
                $key === 'eviction' && $engine->isKeyValue() => in_array($value, self::EVICTIONS, true),
                $key === 'persistence' && $engine->isKeyValue() => in_array($value, self::PERSISTENCES, true),
                default => throw ValidationException::withMessages(["settings.{$key}" => "{$engine->label()} has no setting {$key}."]),
            };

            if (! $valid) {
                throw ValidationException::withMessages(["settings.{$key}" => "Invalid {$key}."]);
            }

            $out[$key] = is_numeric($value) && ! in_array($key, ['eviction', 'persistence'], true) ? (int) $value : (string) $value;
        }

        ksort($out);

        return $out;
    }
}
