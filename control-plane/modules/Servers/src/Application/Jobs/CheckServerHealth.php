<?php

namespace Falak\Servers\Application\Jobs;

use DateTimeImmutable;
use Falak\Alerting\Contracts\AlertConditions;
use Falak\Alerting\Contracts\Data\AlertData;
use Falak\Alerting\Contracts\Severity;
use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentUpgrades;
use Falak\Fleet\Contracts\Data\AgentInfo;
use Falak\Fleet\Contracts\Data\MetricSample;
use Falak\Servers\Application\DiskForecast;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute, for each active server whose agent is online: alerts (AlertConditions: raised once, resolved when they
 * clear) on
 *
 *  - servers.disk_usage: a data filesystem above servers.health.disk_warning_percent (warning) / disk_critical_percent
 *    (critical) of used + available, per mount (the root filesystem alone for agents before v0.10.0);
 *  - servers.disk_forecast: with $forecast (every 15 minutes), a mount a rising line through the last six hours fills
 *    within forecast_hours (DiskForecast; resolved once the forecast is over 1.5 × that, or not filling);
 *  - servers.memory_high / cpu_high / load_high: every sample of the window above the threshold (load: load1 above
 *    cpus × load_per_cpu), the samples covering the window;
 *  - servers.reboot_required: the distribution asked for a reboot (facts);
 *  - servers.agent_outdated: the agent is older than the shipped build for agent_outdated_minutes.
 *
 * Offline agents are skipped (fleet.agent_offline covers them): their conditions neither raise nor resolve.
 */
final class CheckServerHealth implements ShouldQueue
{
    use Queueable;

    public const WINDOW_SLACK_SECONDS = 60;

    public function __construct(public bool $forecast = false) {}

    public function handle(AgentDirectory $agents, AgentUpgrades $upgrades, AlertConditions $conditions): void
    {
        $servers = Server::query()->where('status', ServerStatus::Active)->get(['id', 'organization_id', 'name']);

        foreach ($servers->chunk(200) as $chunk) {
            $ids = $chunk->pluck('id')->all();
            $infos = $agents->forServers($ids);
            $versions = $upgrades->versionsFor($ids);

            foreach ($chunk as $server) {
                $agent = $infos[$server->id] ?? null;

                if ($agent === null || ! $agent->isOnline()) {
                    continue;
                }

                try {
                    $this->check($server, $agent, (bool) ($versions[$server->id]->updateAvailable ?? false), $agents, $conditions);
                } catch (Throwable $e) {
                    Log::warning('Server health check failed.', ['server_id' => $server->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    private function check(Server $server, AgentInfo $agent, bool $outdated, AgentDirectory $agents, AlertConditions $conditions): void
    {
        $config = (array) config('servers.health');
        $org = $server->organization_id;
        $url = "/servers/{$server->id}";
        $now = now()->toDateTimeImmutable();
        $longest = max((int) $config['memory_minutes'], (int) $config['cpu_minutes'], (int) $config['load_minutes']);
        $samples = $agents->metrics($server->id, $now->modify($this->forecast ? '-6 hours' : "-{$longest} minutes")->modify('-'.self::WINDOW_SLACK_SECONDS.' seconds'));

        // Disks: per mount from the latest heartbeat.
        $disks = self::disks($agent);
        $keep = [];

        foreach ($disks as $mount => [$used, $available]) {
            $capacity = $used + $available;
            $percent = $capacity > 0 ? $used / $capacity * 100 : 0.0;

            foreach (['critical' => (float) $config['disk_critical_percent'], 'warning' => (float) $config['disk_warning_percent']] as $level => $threshold) {
                $key = "servers.disk:{$server->id}:{$level}:{$mount}";
                $keep[] = $key;
                $conditions->observe($org, $key, $percent > $threshold, fn () => new AlertData(
                    $org,
                    'servers.disk_usage',
                    $level === 'critical' ? Severity::Critical : Severity::Warning,
                    sprintf('Disk %s on %s is %d%% full', $mount, $server->name, (int) floor($percent)),
                    sprintf('%s of %s used (above %d%%). Free space (old releases, logs, Docker images: docker system prune) or grow the disk before writes fail.',
                        self::bytes($used), self::bytes($capacity), (int) $threshold),
                    $url,
                    context: ['server_id' => $server->id, 'mount' => $mount, 'used_percent' => (int) floor($percent)],
                ), fn () => new AlertData($org, 'servers.disk_usage', Severity::Info,
                    sprintf('Disk %s on %s is below %d%% again', $mount, $server->name, (int) $threshold), sprintf('Now %d%% full.', (int) floor($percent)), $url,
                    context: ['server_id' => $server->id, 'mount' => $mount]));
            }
        }

        $conditions->clearExcept($org, "servers.disk:{$server->id}:", $keep);

        if ($this->forecast) {
            $this->forecasts($server, $disks, $samples, $now, $conditions);
        }

        $this->sustained($server, $agent, $samples, $now, $conditions);

        $conditions->observe($org, "servers.reboot:{$server->id}", ($agent->facts['reboot_required'] ?? false) === true, fn () => new AlertData(
            $org, 'servers.reboot_required', Severity::Warning, "{$server->name} needs a reboot",
            'Installed updates (a kernel or system libraries) only take effect after a restart. Reboot it in a quiet moment.',
            $url, context: ['server_id' => $server->id],
        ));

        $conditions->observe($org, "servers.agent_outdated:{$server->id}", $outdated, fn () => new AlertData(
            $org, 'servers.agent_outdated', Severity::Warning, "The agent on {$server->name} is outdated",
            sprintf('It runs %s, older than the build this control plane ships. Update it from the server page (or Servers → Update all agents).', $agent->version ?? 'an unknown version'),
            $url, context: ['server_id' => $server->id, 'version' => $agent->version],
        ), forSeconds: (int) $config['agent_outdated_minutes'] * 60);
    }

    /**
     * @param  array<string, array{0: int, 1: int, 2: int}>  $disks
     * @param  list<MetricSample>  $samples
     */
    private function forecasts(Server $server, array $disks, array $samples, DateTimeImmutable $now, AlertConditions $conditions): void
    {
        $org = $server->organization_id;
        $hours = (float) config('servers.health.forecast_hours', 48);
        $keep = [];

        foreach ($disks as $mount => [$used, $available]) {
            $series = [];

            foreach ($samples as $sample) {
                $value = $sample->disks[$mount][0] ?? ($mount === '/' && $sample->disks === [] ? $sample->diskUsedBytes : null);

                if ($value !== null) {
                    $series[] = [$sample->at->getTimestamp(), (int) $value];
                }
            }

            $forecast = DiskForecast::hoursUntilFull($series, $used + $available, $now->getTimestamp());
            $key = "servers.disk_forecast:{$server->id}:{$mount}";
            $keep[] = $key;

            if (! $forecast['known']) {
                continue;
            }

            $fills = $forecast['hours'] !== null && $forecast['hours'] < $hours;

            // Hysteresis: resolved only once the forecast is comfortably beyond the threshold (or not filling).
            if (! $fills && $forecast['hours'] !== null && $forecast['hours'] < $hours * 1.5) {
                continue;
            }

            $conditions->observe($org, $key, $fills, fn () => new AlertData(
                $org, 'servers.disk_forecast', Severity::Warning,
                sprintf('Disk %s on %s will be full in about %s', $mount, $server->name, self::duration((float) $forecast['hours'])),
                sprintf('At the rate of the last six hours it fills in about %s (%s free now). Find what is growing (logs, backups staged on disk, uploads) or grow the disk.',
                    self::duration((float) $forecast['hours']), self::bytes($available)),
                "/servers/{$server->id}",
                context: ['server_id' => $server->id, 'mount' => $mount, 'hours_until_full' => round((float) $forecast['hours'], 1)],
            ));
        }

        $conditions->clearExcept($org, "servers.disk_forecast:{$server->id}:", $keep);
    }

    /**
     * @param  list<MetricSample>  $samples
     */
    private function sustained(Server $server, AgentInfo $agent, array $samples, DateTimeImmutable $now, AlertConditions $conditions): void
    {
        $config = (array) config('servers.health');
        $org = $server->organization_id;
        $memory = (int) ($agent->facts['memory_bytes'] ?? 0);
        $cpus = max(1, (int) ($agent->facts['cpus'] ?? 1));
        $loadLimit = $cpus * (float) $config['load_per_cpu'];

        $checks = [
            'memory' => [(int) $config['memory_minutes'], $memory > 0 ? fn (MetricSample $s) => $s->memoryUsedBytes / $memory * 100 : null, (float) $config['memory_percent'], 'servers.memory_high',
                fn (int $m) => sprintf('Memory on %s above %d%% for %d minutes', $server->name, (int) $config['memory_percent'], $m),
                'Processes may be killed for memory soon. Look for a leaking service on the server page, or give the server more memory.'],
            'cpu' => [(int) $config['cpu_minutes'], fn (MetricSample $s) => $s->cpuPercent, (float) $config['cpu_percent'], 'servers.cpu_high',
                fn (int $m) => sprintf('CPU on %s above %d%% for %d minutes', $server->name, (int) $config['cpu_percent'], $m),
                'Requests slow down while the CPU is saturated. Find the busy process on the server page, or scale the server.'],
            'load' => [(int) $config['load_minutes'], fn (MetricSample $s) => $s->load1, $loadLimit, 'servers.load_high',
                fn (int $m) => sprintf('Load on %s above %s for %d minutes', $server->name, rtrim(rtrim(number_format($loadLimit, 1), '0'), '.'), $m),
                sprintf('More work is queued than its %d CPU(s) can run (or processes wait on disk I/O).', $cpus)],
        ];

        foreach ($checks as $name => [$minutes, $value, $threshold, $type, $title, $body]) {
            if ($value === null) {
                continue;
            }

            $held = self::heldAbove($samples, $value, $threshold, $now, $minutes);

            if ($held === null) {
                continue; // not enough samples: no change
            }

            $conditions->observe($org, "servers.{$name}:{$server->id}", $held, fn () => new AlertData(
                $org, $type, Severity::Warning, $title($minutes), $body, "/servers/{$server->id}", context: ['server_id' => $server->id],
            ));
        }
    }

    /**
     * Whether every sample of the last $minutes is above $threshold; null when the samples do not cover the window
     * (a fresh agent, missed heartbeats) or a value is unknown.
     *
     * @param  list<MetricSample>  $samples
     * @param  callable(MetricSample): ?float  $value
     */
    public static function heldAbove(array $samples, callable $value, float $threshold, DateTimeImmutable $now, int $minutes): ?bool
    {
        $since = $now->getTimestamp() - $minutes * 60;
        $window = array_values(array_filter($samples, fn (MetricSample $s) => $s->at->getTimestamp() >= $since - self::WINDOW_SLACK_SECONDS));

        // Heartbeats come every 15 s: the oldest sample must be within 30 s of the window's start.
        if (count($window) < 3 || $window[0]->at->getTimestamp() > $since + 30) {
            return null;
        }

        foreach ($window as $sample) {
            $v = $value($sample);

            if ($v === null) {
                return null;
            }

            if ($v <= $threshold) {
                return false;
            }
        }

        return true;
    }

    /**
     * The agent's data filesystems; agents before v0.10.0 report the root filesystem only.
     *
     * @return array<string, array{0: int, 1: int, 2: int}> mount => [used, available, total]
     */
    private static function disks(AgentInfo $agent): array
    {
        $disks = $agent->metrics['disks'] ?? null;

        if (is_array($disks) && $disks !== []) {
            return array_map(fn ($d) => [(int) ($d[0] ?? 0), (int) ($d[1] ?? 0), (int) ($d[2] ?? 0)], array_filter($disks, 'is_array'));
        }

        $used = (int) ($agent->metrics['disk_used_bytes'] ?? 0);
        $total = (int) ($agent->facts['disk_bytes'] ?? 0);

        return $total > 0 ? ['/' => [$used, max(0, $total - $used), $total]] : [];
    }

    private static function bytes(int $bytes): string
    {
        foreach (['TiB' => 1024 ** 4, 'GiB' => 1024 ** 3, 'MiB' => 1024 ** 2] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1).' '.$unit;
            }
        }

        return intdiv($bytes, 1024).' KiB';
    }

    private static function duration(float $hours): string
    {
        return $hours < 1 ? 'less than an hour' : ($hours < 36 ? round($hours).' hours' : round($hours / 24, 1).' days');
    }
}
