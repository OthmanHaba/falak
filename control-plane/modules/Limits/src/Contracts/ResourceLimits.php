<?php

namespace Falak\Limits\Contracts;

use Illuminate\Validation\Rule;

/**
 * The resource limits of one service (a site, a compose service, a worker or a daemon): stored as JSON on the
 * service's own row, sent to agents in the shape each runtime takes — Docker HostConfig fields
 * (deploy.container.swap, docker.update), a compose override service, a systemd slice (proc.apply `slices`) and
 * supervisor program fields. Null is "unset": no limit (or the environment's default, {@see LimitDefaults}).
 *
 * Memory is in MB, CPUs are fractional cores, the log size is in MB per file.
 */
final readonly class ResourceLimits
{
    public const RESTART_POLICIES = ['always', 'unless-stopped', 'on-failure'];

    public const OOM_PREFERENCES = ['protect', 'normal'];

    public const MIN_MEMORY_MB = 32;

    /** The JSON keys, in display order. */
    public const KEYS = ['memory_limit', 'memory_reservation', 'cpus', 'pids_limit', 'restart_policy', 'max_restarts', 'log_max_size', 'log_max_files', 'oom'];

    /** What docker.update / set-property change on a running service (the rest needs a new container). */
    public const LIVE_KEYS = ['memory_limit', 'memory_reservation', 'cpus', 'pids_limit', 'restart_policy', 'max_restarts'];

    public function __construct(
        public ?int $memoryLimit = null,
        public ?int $memoryReservation = null,
        public ?float $cpus = null,
        public ?int $pidsLimit = null,
        public ?string $restartPolicy = null,
        public ?int $maxRestarts = null,
        public ?int $logMaxSize = null,
        public ?int $logMaxFiles = null,
        public ?string $oom = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data  the stored / validated JSON (unknown keys ignored)
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $int = fn (string $key) => isset($data[$key]) && is_numeric($data[$key]) ? (int) $data[$key] : null;
        $str = fn (string $key, array $allowed) => isset($data[$key]) && in_array($data[$key], $allowed, true) ? (string) $data[$key] : null;

        return new self(
            memoryLimit: $int('memory_limit'),
            memoryReservation: $int('memory_reservation'),
            cpus: isset($data['cpus']) && is_numeric($data['cpus']) ? round((float) $data['cpus'], 2) : null,
            pidsLimit: $int('pids_limit'),
            restartPolicy: $str('restart_policy', self::RESTART_POLICIES),
            maxRestarts: $int('max_restarts'),
            logMaxSize: $int('log_max_size'),
            logMaxFiles: $int('log_max_files'),
            oom: $str('oom', self::OOM_PREFERENCES),
        );
    }

    /**
     * Set values only (what is stored; empty = no limits).
     *
     * @return array<string, int|float|string>
     */
    public function toArray(): array
    {
        return array_filter([
            'memory_limit' => $this->memoryLimit,
            'memory_reservation' => $this->memoryReservation,
            'cpus' => $this->cpus,
            'pids_limit' => $this->pidsLimit,
            'restart_policy' => $this->restartPolicy,
            'max_restarts' => $this->maxRestarts,
            'log_max_size' => $this->logMaxSize,
            'log_max_files' => $this->logMaxFiles,
            'oom' => $this->oom,
        ], fn ($value) => $value !== null);
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /** This service's values, unset ones taken from $defaults (an environment's). */
    public function withDefaults(self $defaults): self
    {
        return self::fromArray([...$defaults->toArray(), ...$this->toArray()]);
    }

    /** Anything a cgroup enforces (a slice on hosts): memory, CPUs or processes. */
    public function hasCgroupLimits(): bool
    {
        return $this->memoryLimit !== null || $this->memoryReservation !== null || $this->cpus !== null || $this->pidsLimit !== null;
    }

    /**
     * Whether going from $this to $next can be applied to running containers (docker.update): only live keys
     * changed, and none was removed (Docker can't lift a memory limit in place).
     */
    public function liveUpdatableTo(self $next): bool
    {
        $before = $this->toArray();
        $after = $next->toArray();

        foreach (self::KEYS as $key) {
            if (($before[$key] ?? null) === ($after[$key] ?? null)) {
                continue;
            }

            if (! in_array($key, self::LIVE_KEYS, true) || ! array_key_exists($key, $after)) {
                return false;
            }
        }

        return true;
    }

    public function oomScoreAdj(): int
    {
        return $this->oom === 'protect' ? (int) config('limits.oom_protect_score', -500) : 0;
    }

    /**
     * Shape rules (bounds against the server are {@see LimitValidator}'s).
     *
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = 'limits'): array
    {
        return [
            $prefix => ['nullable', 'array'],
            "{$prefix}.memory_limit" => ['nullable', 'integer', 'min:'.self::MIN_MEMORY_MB, 'max:4194304'],
            "{$prefix}.memory_reservation" => ['nullable', 'integer', 'min:'.self::MIN_MEMORY_MB, 'max:4194304'],
            "{$prefix}.cpus" => ['nullable', 'numeric', 'min:0.05', 'max:1024'],
            "{$prefix}.pids_limit" => ['nullable', 'integer', 'min:16', 'max:4194304'],
            "{$prefix}.restart_policy" => ['nullable', Rule::in(self::RESTART_POLICIES)],
            "{$prefix}.max_restarts" => ['nullable', 'integer', 'min:1', 'max:1000'],
            "{$prefix}.log_max_size" => ['nullable', 'integer', 'min:1', 'max:10240'],
            "{$prefix}.log_max_files" => ['nullable', 'integer', 'min:1', 'max:100'],
            "{$prefix}.oom" => ['nullable', Rule::in(self::OOM_PREFERENCES)],
        ];
    }

    /** The reservation, never above the limit (Docker, Compose and systemd refuse that; validation already does). */
    private function reservation(): ?int
    {
        return $this->memoryReservation !== null && $this->memoryLimit !== null ? min($this->memoryReservation, $this->memoryLimit) : $this->memoryReservation;
    }

    // ---- Docker -------------------------------------------------------------------------------------------------

    /**
     * deploy.container.swap / docker.run fields.
     *
     * @return array<string, mixed>
     */
    public function docker(): array
    {
        return array_filter([
            'memory_bytes' => $this->memoryLimit !== null ? $this->memoryLimit * 1024 ** 2 : null,
            'memory_reservation_bytes' => $this->reservation() !== null ? $this->reservation() * 1024 ** 2 : null,
            'cpus' => $this->cpus,
            'pids_limit' => $this->pidsLimit,
            'restart_policy' => $this->restartPolicy,
            'max_restarts' => $this->restartPolicy === 'on-failure' ? $this->maxRestarts : null,
            'log' => $this->logMaxSize !== null ? ['max_size_mb' => $this->logMaxSize, 'max_files' => $this->logMaxFiles ?? 1] : null,
            'oom_score_adj' => $this->oomScoreAdj() ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * docker.update fields (the live subset).
     *
     * @return array<string, mixed>
     */
    public function dockerUpdate(): array
    {
        return array_intersect_key($this->docker(), array_flip(['memory_bytes', 'memory_reservation_bytes', 'cpus', 'pids_limit', 'restart_policy', 'max_restarts']));
    }

    /**
     * One service of the compose.falak.yaml override. Both spellings of each limit are set (mem_limit and
     * deploy.resources.limits.memory, …): Compose refuses a file where the two differ, and the override replaces
     * whichever one the user's file had.
     *
     * @return array<string, mixed>
     */
    public function composeService(): array
    {
        $limits = array_filter([
            'memory' => $this->memoryLimit !== null ? "{$this->memoryLimit}M" : null,
            'cpus' => $this->cpus !== null ? (string) $this->cpus : null,
            'pids' => $this->pidsLimit,
        ], fn ($value) => $value !== null);
        $reservations = $this->reservation() !== null ? ['memory' => "{$this->reservation()}M"] : [];

        return array_filter([
            'mem_limit' => $limits['memory'] ?? null,
            'memswap_limit' => $limits['memory'] ?? null,
            'mem_reservation' => $reservations['memory'] ?? null,
            'cpus' => $limits['cpus'] ?? null,
            'pids_limit' => $this->pidsLimit,
            'deploy' => $limits !== [] || $reservations !== [] ? ['resources' => array_filter(['limits' => $limits, 'reservations' => $reservations])] : null,
            'restart' => match ($this->restartPolicy) {
                null => null,
                'on-failure' => $this->maxRestarts !== null ? "on-failure:{$this->maxRestarts}" : 'on-failure',
                default => $this->restartPolicy,
            },
            'logging' => $this->logMaxSize !== null
                ? ['driver' => 'json-file', 'options' => ['max-size' => "{$this->logMaxSize}m", 'max-file' => (string) ($this->logMaxFiles ?? 1)]]
                : null,
            'oom_score_adj' => $this->oomScoreAdj() ?: null,
        ], fn ($value) => $value !== null);
    }

    // ---- Hosts (systemd slices, supervised programs) ------------------------------------------------------------

    /**
     * The slice key of a service: falak-<key>.slice. Dashes nest slices in systemd, so the site's slug has them as
     * underscores (slugs have none of their own); worker and daemon keys are their lower-case ids.
     */
    public static function sliceName(string $kind, string $ref): string
    {
        return $kind.'_'.str_replace('-', '_', strtolower($ref));
    }

    /**
     * proc.apply `slices` entry. MemoryHigh throttles just below the hard limit, MemoryLow protects the reservation.
     *
     * @return array<string, int|string>
     */
    public function slice(string $name): array
    {
        $mb = 1024 ** 2;

        return array_filter([
            'name' => $name,
            'memory_max_bytes' => $this->memoryLimit !== null ? $this->memoryLimit * $mb : null,
            'memory_high_bytes' => $this->memoryLimit !== null ? (int) floor($this->memoryLimit * $mb * (float) config('limits.memory_high_ratio', 0.9)) : null,
            'memory_low_bytes' => $this->reservation() !== null ? $this->reservation() * $mb : null,
            'cpu_quota_percent' => $this->cpus !== null ? max(1, (int) round($this->cpus * 100)) : null,
            'tasks_max' => $this->pidsLimit,
        ], fn ($value) => $value !== null);
    }

    /**
     * proc.apply program fields (merged over the program's own): restart policy (unless-stopped is the
     * supervisor's always), give-up count, log caps and OOM preference.
     *
     * @return array<string, mixed>
     */
    public function program(): array
    {
        return array_filter([
            'restart' => match ($this->restartPolicy) {
                null => null,
                'on-failure' => 'on-failure',
                default => 'always',
            },
            'max_restarts' => $this->maxRestarts,
            'oom_score_adj' => $this->oomScoreAdj() ?: null,
            'log' => $this->logMaxSize !== null ? ['max_bytes' => $this->logMaxSize * 1024 ** 2, 'max_files' => $this->logMaxFiles ?? 1] : null,
        ], fn ($value) => $value !== null);
    }

    /** "512 MB · 1.5 CPUs" for cards; null without memory or CPU limits. */
    public function summary(): ?string
    {
        $parts = array_filter([
            $this->memoryLimit !== null ? self::memoryLabel($this->memoryLimit) : null,
            $this->cpus !== null ? rtrim(rtrim(number_format($this->cpus, 2, '.', ''), '0'), '.').' '.($this->cpus == 1.0 ? 'CPU' : 'CPUs') : null,
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    public static function memoryLabel(int $mb): string
    {
        return $mb >= 1024 && $mb % 1024 === 0 ? ($mb / 1024).' GB' : "{$mb} MB";
    }
}
