<?php

namespace Falak\Functions\Infrastructure;

use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Processes\Contracts\Data\SourcedJob;
use Falak\Processes\Contracts\ScheduleSources;
use Falak\Sites\Contracts\Data\SiteData;

/**
 * A function's enabled schedules as cron jobs of its leader server. Each runs `falak-agent fn-run`, which asks the
 * server's function gateway to run the live release once (falak-fn-run) and exits with the run's code, so the
 * agent's scheduler handles overlap, timeouts and heartbeats as for any job.
 */
final class FunctionScheduleSources implements ScheduleSources
{
    public function __construct(private readonly FunctionStore $functions) {}

    public function jobs(SiteData $site, string $serverId, bool $isLeader): array
    {
        if (! $site->runtime->isFunction() || ! $isLeader || ($function = $this->functions->find($site->id)) === null) {
            return [];
        }

        $jobs = [];

        foreach (FunctionSchedule::query()->where('function_id', $function->id)->where('enabled', true)->orderBy('created_at')->get() as $schedule) {
            $jobs[] = new SourcedJob(
                key: $schedule->key(),
                kind: 'function',
                label: $schedule->name,
                schedule: $schedule->expression,
                timezone: $schedule->timezone,
                command: self::command($site->slug, $schedule),
                user: 'root',
                overlap: $schedule->overlap,
                // The gateway stops the run at its timeout; the scheduler's own limit is a little later.
                timeoutSeconds: $schedule->timeout_s + 30,
            );
        }

        return $jobs;
    }

    public static function command(string $slug, FunctionSchedule $schedule): string
    {
        return implode(' ', [
            '/usr/local/bin/falak-agent fn-run',
            '--site', escapeshellarg($slug),
            '--schedule', escapeshellarg($schedule->key()),
            '--name', escapeshellarg($schedule->name),
            '--cron', escapeshellarg($schedule->expression),
            '--timeout', (string) $schedule->timeout_s,
        ]);
    }
}
