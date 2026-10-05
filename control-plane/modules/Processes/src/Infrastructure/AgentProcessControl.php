<?php

namespace Falak\Processes\Infrastructure;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Processes\Application\ServerConverger;
use Falak\Processes\Contracts\OctaneRouting;
use Falak\Processes\Contracts\ProcessControl;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Events\ProcessesRestarted;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\TargetStatus;
use Throwable;

final class AgentProcessControl implements ProcessControl
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly AgentGateway $agents,
        private readonly ServerConverger $converger,
        private readonly OctaneRouting $octane,
    ) {}

    public const RELOAD_PREFIX = 'processes.octane-reload:';

    public function restartForSite(string $siteId, ?string $serverId = null, bool $newRelease = true): array
    {
        $site = $this->sites->find(strtolower($siteId));

        if ($site === null) {
            return [];
        }

        $serverId = $serverId !== null ? strtolower($serverId) : null;
        $timeout = (int) config('processes.restart_timeout_seconds', 300);
        $handles = [];
        $servers = [];

        foreach ($site->targets as $target) {
            if ($target->status !== TargetStatus::Ready || ($serverId !== null && $target->serverId !== $serverId)) {
                continue;
            }

            // Converge first: after a deploy the site's programs carry the new release's ids + environment, so
            // proc.apply restarts every program whose definition changed (and starts them on the first deploy).
            // Only the site's running programs it leaves unchanged still need a restart.
            $before = ServerState::query()->find($target->serverId)?->programs ?? [];
            $apply = null;

            try {
                $apply = $this->converger->converge($target->serverId)['proc'];
            } catch (Throwable $e) {
                Log::warning('processes: converge before restart failed', ['site_id' => $site->id, 'server_id' => $target->serverId, 'error' => $e->getMessage()]);
            }

            $state = ServerState::query()->find($target->serverId);
            $programs = $state?->programs ?? [];
            $running = array_values(array_filter(
                $state?->applied_programs ?? [],
                fn (string $name) => ($programs[$name]['site_id'] ?? null) === $site->id,
            ));

            if ($apply !== null) {
                $handles[] = $apply;
                $servers[] = $target->serverId;
                $running = array_values(array_filter($running, fn (string $name) => isset($programs[$name]['hash'], $before[$name]['hash'])
                    && $programs[$name]['hash'] === $before[$name]['hash']));
            }

            if ($running === []) {
                continue;
            }

            $horizon = ProgramNames::horizon($site->slug);
            $octane = ProgramNames::octane($site->slug);
            $reload = ! $newRelease && in_array($octane, $running, true) && $site->laravel->octaneServer !== null
                && $this->octane->listeningPort($site->id, $target->serverId) !== null;
            $others = array_values(array_diff($running, $reload ? [$horizon, $octane] : [$horizon]));
            $key = "processes.restart:{$site->id}:{$target->serverId}:".Str::ulid();
            $env = ['FALAK_SITE_ID' => strtoupper($site->id), 'FALAK_SERVER_ID' => strtoupper($target->serverId)];

            try {
                if (in_array($horizon, $running, true)) {
                    // Horizon drains its workers and exits; the supervisor (restart: always) starts it on the new release.
                    $handles[] = $this->agents->dispatch($target->serverId, 'system.exec', [
                        'script' => $site->phpBinary().' artisan horizon:terminate',
                        'shell' => '/bin/bash',
                        'user' => $site->unixUser,
                        'cwd' => $site->currentPath(),
                        'env' => $env,
                    ], $timeout, "{$key}:horizon");
                }

                if ($reload) {
                    // Graceful: Octane re-boots its workers behind the open port. A failure falls back to proc.restart
                    // (HandleCommandOutcome).
                    $handles[] = $this->agents->dispatch($target->serverId, 'system.exec', [
                        'script' => $site->phpBinary().' artisan octane:reload --server='.$site->laravel->octaneServer?->value,
                        'shell' => '/bin/bash',
                        'user' => $site->unixUser,
                        'cwd' => $site->currentPath(),
                        'env' => $env,
                    ], $timeout, self::RELOAD_PREFIX."{$site->id}:{$target->serverId}:".Str::ulid());
                }

                if ($others !== []) {
                    $handles[] = $this->agents->dispatch($target->serverId, 'proc.restart', ['names' => $others, 'site' => $site->slug], $timeout, $key);
                }
            } catch (AgentUnavailable) {
                continue;
            }

            $servers[] = $target->serverId;
        }

        if ($handles !== []) {
            ProcessesRestarted::dispatch($site->id, $site->organizationId, array_values(array_unique($servers)), array_map(fn ($handle) => $handle->id, $handles));
        }

        return $handles;
    }

    public function converge(string ...$serverIds): void
    {
        $this->converger->schedule(...array_map('strtolower', $serverIds));
    }

    /**
     * `octane:reload` failed (not running, state file missing …): restart the program instead.
     */
    public function reloadFailed(string $idempotencyKey): ?CommandHandle
    {
        [$siteId, $serverId] = array_pad(explode(':', substr($idempotencyKey, strlen(self::RELOAD_PREFIX))), 2, '');
        $site = $this->sites->find($siteId);

        if ($site === null || $serverId === '') {
            return null;
        }

        try {
            return $this->agents->dispatch($serverId, 'proc.restart', ['names' => [ProgramNames::octane($site->slug)], 'site' => $site->slug], (int) config('processes.restart_timeout_seconds', 300), "processes.restart:{$site->id}:{$serverId}:".Str::ulid().':octane');
        } catch (AgentUnavailable) {
            return null;
        }
    }
}
