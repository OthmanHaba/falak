<?php

namespace Falak\Limits\Application\Listeners;

use Falak\Fleet\Events\AgentServiceEventsReported;
use Falak\Limits\Application\ResolvedService;
use Falak\Limits\Application\ServiceResolver;
use Falak\Limits\Domain\Models\ServiceState;
use Falak\Limits\Events\ServiceOomKilled;
use Falak\Limits\Events\ServiceRestartLoop;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OOM kills and restarts from heartbeats: kept per service (badges, restart windows) and raised as
 * {@see ServiceOomKilled} for every report and {@see ServiceRestartLoop} once per window when a service restarted
 * config('limits.restart_loop.restarts') times within it.
 */
final class RecordServiceEvents implements ShouldQueue
{
    /** One event never stands for more (a counter gone wild, a forged report). */
    public const MAX_COUNT = 1000;

    public function __construct(
        private readonly ServiceResolver $resolver,
        private readonly ServerDirectory $servers,
    ) {}

    public function handle(AgentServiceEventsReported $event): void
    {
        $server = $this->servers->find($event->serverId);

        if ($server === null || $server->organizationId !== $event->organizationId) {
            return;
        }

        foreach ($event->events as $reported) {
            $service = $this->resolver->resolve($event->organizationId, $event->serverId, $reported);

            if ($service === null) {
                continue;
            }

            // The agent's time only filters (its clock is not trusted): an event from the future or older than a day
            // (a queue that survived a long outage) is dropped. Windows, badges and alert dedup use the time it arrived.
            try {
                $reportedAt = Carbon::parse($reported['at']);
            } catch (Throwable) {
                continue;
            }

            if ($reportedAt->gt(now()->addMinutes(5)) || $reportedAt->lt(now()->subDay())) {
                continue;
            }

            $at = now();
            $count = min(self::MAX_COUNT, max(1, (int) $reported['count']));

            match ($reported['kind']) {
                'oom_kill' => $this->oom($event->serverId, $server->name, $service, $count, $at),
                'restart' => $this->restart($event->serverId, $server->name, $service, $count, $at),
                default => null,
            };
        }
    }

    private function oom(string $serverId, string $serverName, ResolvedService $service, int $count, Carbon $at): void
    {
        $state = $this->state($serverId, $service);
        $state->forceFill(['oom_kills' => $state->oom_kills + $count, 'last_oom_at' => $at])->save();

        ServiceOomKilled::dispatch($service->organizationId, $serverId, $serverName, $service->kind, $service->id, $service->siteId, $service->label, $count,
            $service->memoryLimitMb, $service->url, $at->toIso8601String());
    }

    private function restart(string $serverId, string $serverName, ResolvedService $service, int $count, Carbon $at): void
    {
        $window = max(1, (int) config('limits.restart_loop.window_minutes', 60));
        $threshold = max(1, (int) config('limits.restart_loop.restarts', 5));

        $loop = DB::transaction(function () use ($serverId, $service, $count, $at, $window, $threshold) {
            $state = $this->state($serverId, $service, lock: true);

            if ($state->window_started_at === null || $state->window_started_at->lt($at->copy()->subMinutes($window))) {
                $state->forceFill(['window_started_at' => $at, 'window_restarts' => 0, 'restart_loop_at' => null]);
            }

            $state->forceFill([
                'restarts' => $state->restarts + $count,
                'window_restarts' => $state->window_restarts + $count,
                'last_restart_at' => $at,
            ]);

            $loop = $state->restart_loop_at === null && $state->window_restarts >= $threshold;

            if ($loop) {
                $state->restart_loop_at = $at;
            }

            $state->save();

            return $loop ? $state : null;
        });

        if ($loop !== null) {
            ServiceRestartLoop::dispatch($service->organizationId, $serverId, $serverName, $service->kind, $service->id, $service->siteId, $service->label,
                $loop->window_restarts, $window, $service->url, $loop->window_started_at?->toIso8601String() ?? $at->toIso8601String());
        }
    }

    private function state(string $serverId, ResolvedService $service, bool $lock = false): ServiceState
    {
        $query = ServiceState::query()->where('server_id', $serverId)->where('service_kind', $service->kind)->where('service_id', $service->id);

        $state = ($lock ? $query->clone()->lockForUpdate() : $query->clone())->first();

        if ($state === null) {
            // One row per service and server (unique index): a concurrent report that created it first wins.
            try {
                $state = ServiceState::query()->create([
                    'organization_id' => $service->organizationId,
                    'server_id' => $serverId,
                    'site_id' => $service->siteId,
                    'service_kind' => $service->kind,
                    'service_id' => $service->id,
                    'label' => $service->label,
                ]);
            } catch (UniqueConstraintViolationException) {
                $state = ($lock ? $query->clone()->lockForUpdate() : $query->clone())->firstOrFail();
            }
        }

        /** @var ServiceState $state */
        $state->label = $service->label;

        return $state;
    }
}
