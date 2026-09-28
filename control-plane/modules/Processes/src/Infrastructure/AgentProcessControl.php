<?php

namespace Kiln\Processes\Infrastructure;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Contracts\ProcessControl;
use Kiln\Processes\Domain\Models\ServerState;
use Kiln\Processes\Events\ProcessesRestarted;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\TargetStatus;
use Throwable;

final class AgentProcessControl implements ProcessControl
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly AgentGateway $agents,
        private readonly ServerConverger $converger,
    ) {}

    public function restartForSite(string $siteId, ?string $serverId = null): array
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
            $others = array_values(array_diff($running, [$horizon]));
            $key = "processes.restart:{$site->id}:{$target->serverId}:".Str::ulid();

            try {
                if (in_array($horizon, $running, true)) {
                    // Horizon drains its workers and exits; the supervisor (restart: always) starts it on the new release.
                    $handles[] = $this->agents->dispatch($target->serverId, 'system.exec', [
                        'script' => $site->phpBinary().' artisan horizon:terminate',
                        'shell' => '/bin/bash',
                        'user' => $site->unixUser,
                        'cwd' => $site->currentPath(),
                        'env' => ['KILN_SITE_ID' => strtoupper($site->id), 'KILN_SERVER_ID' => strtoupper($target->serverId)],
                    ], $timeout, "{$key}:horizon");
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
}
