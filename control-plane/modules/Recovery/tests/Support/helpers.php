<?php

use Falak\Alerting\Contracts\Alerts;
use Falak\Alerting\Contracts\Data\AlertData;

require_once __DIR__.'/../../../Databases/tests/Support/helpers.php';
require_once __DIR__.'/../../../Processes/tests/Support/helpers.php';
require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';
require_once __DIR__.'/../../../../tests/Support/FakeAgentGateway.php';

/**
 * Write state/dr.json as falak-ctl does and point the panel at it. Null removes it (falak-ctl never ran).
 *
 * @param  array<string, mixed>|null  $overrides
 */
function recovery_status_file(?array $overrides = []): string
{
    $path = sys_get_temp_dir().'/falak-recovery-test-'.getmypid().'.json';
    config(['recovery.status_path' => $path]);

    if ($overrides === null) {
        @unlink($path);

        return $path;
    }

    $status = array_replace_recursive([
        'version' => 1,
        'updated_at' => now()->toIso8601String(),
        'falak_version' => 'v0.10.0',
        'configured' => true,
        'encrypted' => true,
        'target' => 's3://dr-bucket/falak',
        'endpoint' => 'https://s3.example.com',
        'schedule_hours' => 6,
        'drill_schedule' => 'monthly',
        'include_registry' => false,
        'last_backup' => ['name' => 'falak-backup-x.tar.gz.enc', 'at' => now()->subHour()->toIso8601String(), 'size_bytes' => 1234, 'uploaded' => true, 'encrypted' => true, 'kek_id' => 'abcd'],
        'last_failure' => ['at' => null, 'error' => null],
        'last_drill' => ['at' => now()->subDays(3)->toIso8601String(), 'ok' => true, 'backup' => 'falak-backup-x.tar.gz.enc', 'duration_s' => 95, 'message' => 'all checks passed'],
    ], $overrides);

    file_put_contents($path, json_encode($status));

    return $path;
}

final class RecoveryRecordingAlerts implements Alerts
{
    /** @var list<AlertData> */
    public array $raised = [];

    public function raise(AlertData $alert): void
    {
        $this->raised[] = $alert;
    }

    /** @return list<AlertData> */
    public function of(string $type, ?bool $resolves = null): array
    {
        return array_values(array_filter($this->raised, fn (AlertData $alert) => $alert->type === $type && ($resolves === null || $alert->resolves === $resolves)));
    }
}

function recovery_alerts(): RecoveryRecordingAlerts
{
    $alerts = new RecoveryRecordingAlerts;
    app()->instance(Alerts::class, $alerts);

    return $alerts;
}
