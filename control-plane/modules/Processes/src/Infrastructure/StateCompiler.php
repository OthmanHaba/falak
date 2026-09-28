<?php

namespace Kiln\Processes\Infrastructure;

use Illuminate\Support\Collection;
use Kiln\Deployments\Contracts\Data\LiveRelease;
use Kiln\Deployments\Contracts\LiveReleases;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Processes\Domain\Models\Worker;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetStatus;

/**
 * Compiles the complete desired proc.apply and cron.apply payloads of one server from every site
 * targeting it — ready targets only (the site user must exist before anything runs as it), and only once
 * the site has a live release on that server (Deployments' {@see LiveReleases}): before the first deploy
 * there is no `current/` to run in, so a site's programs and schedules start on its first activation.
 *
 * Every program / job env carries the live release's site variables (the ones its `.env` was written
 * with — Node and Deno don't read `.env`) plus KILN_RELEASE_ID / KILN_DEPLOYMENT_ID, so a deploy changes
 * the program definitions and proc.apply restarts them with the new release's environment.
 *
 * Ids cross the agent boundary upper-case (KILN_SITE_ID / KILN_SERVER_ID), like telemetry.configure.
 */
final class StateCompiler
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly LiveReleases $releases,
    ) {}

    public function compile(string $serverId): CompiledState
    {
        $live = $this->releases->onServer($serverId);
        $sites = array_values(array_filter(
            $this->sites->forServer($serverId),
            fn (SiteData $site) => ! $site->runtime->isContainer() && $site->target($serverId)?->status === TargetStatus::Ready && isset($live[$site->id]),
        ));

        $siteIds = array_map(fn (SiteData $site) => $site->id, $sites);
        $workers = Worker::query()->whereIn('site_id', $siteIds)->orderBy('created_at')->orderBy('id')->get()->groupBy('site_id');
        $daemons = Daemon::query()->whereIn('site_id', $siteIds)->orderBy('created_at')->orderBy('id')->get()->groupBy('site_id');
        $schedules = Schedule::query()->whereIn('site_id', $siteIds)->where('enabled', true)->orderBy('created_at')->orderBy('id')->get()->groupBy('site_id');

        $programs = [];
        $programMeta = [];
        $jobs = [];
        $jobMeta = [];

        foreach ($sites as $site) {
            $isLeader = $site->target($serverId)?->isLeader() ?? false;

            $release = $live[$site->id];

            foreach ($this->sitePrograms($site, $serverId, $release, $workers->get($site->id, new Collection), $daemons->get($site->id, new Collection)) as [$program, $kind, $label]) {
                $programs[] = $program;
                // `hash` tells restartForSite() which programs a proc.apply restarts anyway (changed definition).
                $programMeta[$program['name']] = ['site_id' => $site->id, 'kind' => $kind, 'label' => $label, 'numprocs' => $program['numprocs'] ?? 1, 'hash' => PayloadHash::of($program)];
            }

            foreach ($this->siteJobs($site, $serverId, $release, $isLeader, $schedules->get($site->id, new Collection)) as [$job, $kind, $label]) {
                $jobs[] = $job;
                $jobMeta[$job['name']] = [
                    'site_id' => $site->id,
                    'kind' => $kind,
                    'label' => $label,
                    'schedule' => $job['schedule'],
                    'timezone' => $job['timezone'] ?? 'UTC',
                    'heartbeat' => $job['heartbeat'] ?? true,
                ];
            }
        }

        usort($programs, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        usort($jobs, fn (array $a, array $b) => strcmp($a['name'], $b['name']));
        ksort($programMeta);
        ksort($jobMeta);

        return new CompiledState($programs, $jobs, $programMeta, $jobMeta);
    }

    /**
     * Program definitions of a site: [program, kind, label].
     *
     * @param  Collection<int, Worker>  $workers
     * @param  Collection<int, Daemon>  $daemons
     * @return list<array{0: array<string, mixed>, 1: string, 2: string}>
     */
    private function sitePrograms(SiteData $site, string $serverId, LiveRelease $release, Collection $workers, Collection $daemons): array
    {
        $out = [];
        $php = $site->phpBinary();
        $laravel = $site->framework->isLaravel() && $site->runtime->isPhp();

        // JavaScript runtimes serve the site themselves (Caddy proxies to app_port): supervise the
        // package's conventional start script in the current release.
        $start = match ($site->runtime) {
            SiteRuntime::Node => ['npm', 'run', 'start'],
            SiteRuntime::Bun => ['bun', 'run', 'start'],
            SiteRuntime::Deno => ['deno', 'task', 'start'],
            default => null,
        };

        if ($start !== null && $site->appPort !== null) {
            $out[] = [$this->program($site, $serverId, $release, ProgramNames::app($site->slug), $start, [], [
                // The site's NODE_ENV wins; PORT/HOST must match what Caddy proxies to.
                'NODE_ENV' => $release->environment['NODE_ENV'] ?? 'production',
                'PORT' => (string) $site->appPort,
                'HOST' => '127.0.0.1',
                'PATH' => '/usr/local/bin:/usr/bin:/bin',
            ]), 'app', 'Web process'];
        }

        if ($laravel && $site->laravel->horizon) {
            $out[] = [$this->program($site, $serverId, $release, ProgramNames::horizon($site->slug), [$php, 'artisan', 'horizon'], [
                'stop_timeout_s' => max(1, (int) config('processes.horizon_stop_timeout', 120)),
            ]), 'horizon', 'Horizon'];
        }

        if ($laravel && $site->laravel->octane) {
            $server = $site->runtime === SiteRuntime::FrankenPhp ? 'frankenphp' : 'swoole';
            $out[] = [$this->program($site, $serverId, $release, ProgramNames::octane($site->slug), [
                $php, 'artisan', 'octane:start', "--server={$server}", '--host=127.0.0.1', '--port='.self::octanePort($site),
            ]), 'octane', 'Octane'];
        }

        foreach ($workers as $worker) {
            if (! $worker->runsOn($serverId)) {
                continue;
            }

            $command = $this->workerCommand($site, $worker);

            if ($command === null) {
                continue;
            }

            $out[] = [$this->program($site, $serverId, $release, ProgramNames::worker($site->slug, $worker->id), $command, [
                'numprocs' => max(1, min(64, $worker->processes)),
                // queue:work finishes the current job on SIGTERM; give it the job timeout plus a margin.
                'stop_timeout_s' => max(1, $worker->timeout + 15),
            ], $worker->env ?? []), 'worker', self::workerLabel($worker)];
        }

        foreach ($daemons as $daemon) {
            if (! $daemon->runsOn($serverId)) {
                continue;
            }

            $out[] = [$this->program($site, $serverId, $release, ProgramNames::daemon($site->slug, $daemon->id), ['/bin/bash', '-c', $daemon->command], [
                'numprocs' => max(1, min(64, $daemon->instances)),
                'restart' => $daemon->restart,
                'stop_signal' => $daemon->stop_signal,
                'stop_timeout_s' => max(1, $daemon->stop_timeout),
                'user' => $daemon->user ?: $site->unixUser,
                'cwd' => $daemon->directory ?: $site->currentPath(),
            ], $daemon->env ?? []), 'daemon', $daemon->name];
        }

        return $out;
    }

    /**
     * @param  Collection<int, Schedule>  $schedules
     * @return list<array{0: array<string, mixed>, 1: string, 2: string}>
     */
    private function siteJobs(SiteData $site, string $serverId, LiveRelease $release, bool $isLeader, Collection $schedules): array
    {
        $out = [];

        if ($isLeader && $site->framework->isLaravel() && $site->runtime->isPhp() && $site->laravel->scheduler) {
            $out[] = [[
                'name' => ProgramNames::scheduler($site->slug),
                'schedule' => '* * * * *',
                'command' => $site->phpBinary().' artisan schedule:run',
                'user' => $site->unixUser,
                'cwd' => $site->currentPath(),
                'env' => $this->env($site, $serverId, $release),
                'timezone' => 'UTC',
                // Laravel handles overlapping itself (withoutOverlapping); a slow run must not skip the next minute.
                'overlap' => 'allow',
                'timeout_s' => max(1, (int) config('processes.scheduler_timeout', 3600)),
                'heartbeat' => true,
                'site' => $site->slug,
            ], 'scheduler', 'Laravel scheduler'];
        }

        foreach ($schedules as $schedule) {
            if (! $isLeader && ! $schedule->all_servers) {
                continue;
            }

            $out[] = [[
                'name' => ProgramNames::cron($site->slug, $schedule->id),
                'schedule' => $schedule->expression,
                'command' => $schedule->command,
                'user' => $schedule->user ?: $site->unixUser,
                'cwd' => $site->currentPath(),
                'env' => $this->env($site, $serverId, $release),
                'timezone' => $schedule->timezone ?: 'UTC',
                'overlap' => $schedule->overlap,
                'timeout_s' => max(1, $schedule->timeout),
                'heartbeat' => $schedule->heartbeat,
                'site' => $site->slug,
            ], 'custom', $schedule->name];
        }

        return $out;
    }

    /**
     * @return list<string>|null
     */
    private function workerCommand(SiteData $site, Worker $worker): ?array
    {
        if ($worker->command !== null && $worker->command !== '') {
            return ['/bin/bash', '-c', $worker->command];
        }

        if (! $site->runtime->isPhp()) {
            return null;
        }

        $argv = [$site->phpBinary(), 'artisan', 'queue:work'];

        if ($worker->connection) {
            $argv[] = $worker->connection;
        }

        if ($worker->queues() !== []) {
            $argv[] = '--queue='.implode(',', $worker->queues());
        }

        $argv[] = "--sleep={$worker->sleep}";
        $argv[] = "--tries={$worker->tries}";
        $argv[] = "--timeout={$worker->timeout}";
        $argv[] = "--memory={$worker->memory}";

        if ($worker->backoff !== null) {
            $argv[] = "--backoff={$worker->backoff}";
        }

        if ($worker->max_jobs) {
            $argv[] = "--max-jobs={$worker->max_jobs}";
        }

        if ($worker->max_time) {
            $argv[] = "--max-time={$worker->max_time}";
        }

        return $argv;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private function program(SiteData $site, string $serverId, LiveRelease $release, string $name, array $command, array $overrides = [], array $env = []): array
    {
        return array_filter([
            'name' => $name,
            'command' => $command,
            'user' => $site->unixUser,
            'cwd' => $site->currentPath(),
            'env' => $this->env($site, $serverId, $release, $env),
            'numprocs' => 1,
            'autostart' => true,
            'restart' => 'always',
            'stop_signal' => 'TERM',
            'stop_timeout_s' => 30,
            'site' => $site->slug,
            ...$overrides,
        ], fn ($value) => $value !== null);
    }

    /**
     * The release's site variables, then the program's own env, then Kiln's ids (which always win).
     *
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function env(SiteData $site, string $serverId, LiveRelease $release, array $extra = []): array
    {
        $variables = array_filter($release->environment, fn ($value, $key) => preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key) === 1, ARRAY_FILTER_USE_BOTH);

        return [
            ...array_map('strval', $variables),
            ...array_map('strval', $extra),
            'KILN_SITE' => $site->slug,
            'KILN_SITE_ID' => strtoupper($site->id),
            'KILN_SERVER_ID' => strtoupper($serverId),
            'KILN_RELEASE_ID' => strtoupper($release->releaseId),
            'KILN_DEPLOYMENT_ID' => strtoupper($release->deploymentId),
        ];
    }

    public static function octanePort(SiteData $site): int
    {
        if ($site->appPort !== null) {
            return $site->appPort;
        }

        $span = max(1, (int) config('processes.octane_port_span', 1000));

        return (int) config('processes.octane_port_base', 8000) + (crc32($site->id) % $span);
    }

    public static function workerLabel(Worker $worker): string
    {
        if ($worker->command) {
            return 'Worker: '.mb_strimwidth($worker->command, 0, 60, '…');
        }

        $queues = $worker->queues() === [] ? 'default' : implode(', ', $worker->queues());

        return 'Queue worker ('.($worker->connection ? "{$worker->connection}: " : '').$queues.')';
    }
}
