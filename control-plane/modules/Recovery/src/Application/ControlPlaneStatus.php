<?php

namespace Falak\Recovery\Application;

use Illuminate\Support\Carbon;

/**
 * The control plane's disaster recovery as falak-ctl last wrote it (state/dr.json, read-only in the container): whether
 * backups go to a bucket on a schedule, encrypted; the last backup, failure and drill. The file holds no secrets.
 * Missing or unreadable: falak-ctl never ran on this host (a development checkout), or it predates DR.
 */
final class ControlPlaneStatus
{
    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private bool $loaded = false;

    public function __construct(private readonly ?string $path = null) {}

    public function available(): bool
    {
        return $this->data() !== null;
    }

    /** DR is configured: a bucket, the passphrase and the backup timer (falak-ctl dr setup). */
    public function configured(): bool
    {
        return ($this->data()['configured'] ?? false) === true;
    }

    /**
     * Configured or not as far as the panel can tell. Without the file (falak-ctl never wrote it), a production
     * install counts as not configured; elsewhere (development) nothing is asked.
     */
    public function needsSetup(): bool
    {
        return $this->available() ? ! $this->configured() : app()->environment('production');
    }

    public function scheduleHours(): int
    {
        $hours = (int) ($this->data()['schedule_hours'] ?? 6);

        return $hours > 0 ? $hours : 6;
    }

    public function lastBackupAt(): ?Carbon
    {
        return self::time($this->data()['last_backup']['at'] ?? null);
    }

    public function lastFailureAt(): ?Carbon
    {
        return self::time($this->data()['last_failure']['at'] ?? null);
    }

    public function lastFailureError(): ?string
    {
        $error = $this->data()['last_failure']['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    /** The last scheduled backup failed (after the last one that succeeded). */
    public function backupFailing(): bool
    {
        $failed = $this->lastFailureAt();
        $last = $this->lastBackupAt();

        return $failed !== null && ($last === null || $failed->greaterThan($last));
    }

    /** No backup within twice the schedule (or none at all once configured). */
    public function backupMissing(?Carbon $now = null): bool
    {
        if (! $this->configured()) {
            return false;
        }

        $last = $this->lastBackupAt();

        return $last === null || $last->lessThan(($now ?? now())->copy()->subHours(2 * $this->scheduleHours()));
    }

    public function lastDrillAt(): ?Carbon
    {
        return self::time($this->data()['last_drill']['at'] ?? null);
    }

    public function drillFailed(): bool
    {
        return $this->lastDrillAt() !== null && ($this->data()['last_drill']['ok'] ?? null) === false;
    }

    /**
     * What Settings → Disaster recovery shows.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = $this->data() ?? [];
        $last = $this->lastBackupAt();

        return [
            'available' => $this->available(),
            'configured' => $this->configured(),
            'needs_setup' => $this->needsSetup(),
            'updated_at' => self::time($data['updated_at'] ?? null)?->toIso8601String(),
            'falak_version' => is_string($data['falak_version'] ?? null) ? $data['falak_version'] : null,
            'encrypted' => ($data['encrypted'] ?? false) === true,
            'target' => is_string($data['target'] ?? null) ? $data['target'] : null,
            'endpoint' => is_string($data['endpoint'] ?? null) ? $data['endpoint'] : null,
            'schedule_hours' => $this->scheduleHours(),
            'drill_schedule' => is_string($data['drill_schedule'] ?? null) ? $data['drill_schedule'] : null,
            'include_registry' => ($data['include_registry'] ?? false) === true,
            'last_backup' => $last === null ? null : [
                'name' => (string) ($data['last_backup']['name'] ?? ''),
                'at' => $last->toIso8601String(),
                'age_seconds' => (int) max(0, $last->diffInSeconds(now(), true)),
                'size_bytes' => is_int($data['last_backup']['size_bytes'] ?? null) ? $data['last_backup']['size_bytes'] : null,
                'uploaded' => ($data['last_backup']['uploaded'] ?? false) === true,
                'encrypted' => ($data['last_backup']['encrypted'] ?? false) === true,
            ],
            'backup_failing' => $this->backupFailing(),
            'backup_missing' => $this->backupMissing(),
            'last_failure' => $this->lastFailureAt() === null ? null : ['at' => $this->lastFailureAt()->toIso8601String(), 'error' => $this->lastFailureError()],
            'last_drill' => $this->lastDrillAt() === null ? null : [
                'at' => $this->lastDrillAt()->toIso8601String(),
                'ok' => ($data['last_drill']['ok'] ?? null) === true,
                'backup' => is_string($data['last_drill']['backup'] ?? null) ? $data['last_drill']['backup'] : null,
                'duration_s' => is_int($data['last_drill']['duration_s'] ?? null) ? $data['last_drill']['duration_s'] : null,
                'message' => is_string($data['last_drill']['message'] ?? null) ? $data['last_drill']['message'] : null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function data(): ?array
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $path = $this->path ?? (string) config('recovery.status_path');
            $raw = $path !== '' && is_file($path) && is_readable($path) ? @file_get_contents($path, length: 1 << 20) : false;
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $this->data = is_array($data) ? $data : null;
        }

        return $this->data;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
