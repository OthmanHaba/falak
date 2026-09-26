<?php

namespace Kiln\Processes\Application;

use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Data\CommandHandle;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Processes\Domain\Models\ServerState;
use Kiln\Processes\Events\ProgramCrashLooping;
use Kiln\Processes\Events\ProgramRecovered;
use Kiln\Servers\Contracts\ServerDirectory;

/**
 * proc.status requests and their results: stores the latest snapshot per server and detects programs
 * that keep crashing (fatal, or in backoff after many restarts), raising / resolving alerts.
 */
final class StatusPoller
{
    public const KEY_PREFIX = 'processes.status:';

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
    ) {}

    public function request(string $serverId): ?CommandHandle
    {
        try {
            return $this->agents->dispatch($serverId, 'proc.status', (object) [], 30, self::KEY_PREFIX.$serverId.':'.Str::ulid());
        } catch (AgentUnavailable) {
            return null;
        }
    }

    public static function isStatusCommand(?string $idempotencyKey): bool
    {
        return $idempotencyKey !== null && str_starts_with($idempotencyKey, self::KEY_PREFIX);
    }

    /**
     * @param  array<string, mixed>|null  $result  proc.status `$defs.result`
     */
    public function record(string $serverId, ?array $result): void
    {
        $state = ServerState::query()->find($serverId);

        if ($state === null || ! is_array($result['processes'] ?? null)) {
            return;
        }

        $processes = array_values(array_filter($result['processes'], fn ($p) => is_array($p) && is_string($p['name'] ?? null)));
        $programs = $state->programs ?? [];
        $threshold = max(1, (int) config('processes.crash_loop_restarts', 5));
        $looping = [];
        $running = [];

        foreach ($processes as $process) {
            $name = $process['name'];

            if (! isset($programs[$name])) {
                continue;
            }

            $status = (string) ($process['state'] ?? '');
            $restarts = (int) ($process['restarts'] ?? 0);

            if ($status === 'fatal' || ($status === 'backoff' && $restarts >= $threshold)) {
                $looping[$name] ??= $process;
            } elseif ($status === 'running') {
                $running[$name] = true;
            }
        }

        $previous = $state->crash_looping ?? [];
        $serverName = $this->servers->find($serverId)?->name ?? $serverId;
        $still = [];

        foreach ($looping as $name => $process) {
            $still[] = $name;

            if (! in_array($name, $previous, true)) {
                ProgramCrashLooping::dispatch(
                    $state->organization_id,
                    $serverId,
                    $serverName,
                    $programs[$name]['site_id'],
                    $name,
                    $programs[$name]['label'],
                    (string) $process['state'],
                    (int) ($process['restarts'] ?? 0),
                    isset($process['last_exit_code']) ? (int) $process['last_exit_code'] : null,
                    self::url($programs[$name]['site_id'], $programs[$name]['kind']),
                );
            }
        }

        foreach ($previous as $name) {
            if (in_array($name, $still, true)) {
                continue;
            }

            if (isset($programs[$name], $running[$name])) {
                ProgramRecovered::dispatch($state->organization_id, $serverId, $serverName, $programs[$name]['site_id'], $name, $programs[$name]['label'], self::url($programs[$name]['site_id'], $programs[$name]['kind']));
            } elseif (isset($programs[$name])) {
                // Neither crashing nor running yet (e.g. starting): keep watching.
                $still[] = $name;
            }
        }

        sort($still);

        $state->forceFill(['process_status' => $processes, 'status_at' => now(), 'crash_looping' => $still === [] ? null : $still])->save();
    }

    public static function url(string $siteId, string $kind): string
    {
        return "/sites/{$siteId}/".($kind === 'daemon' ? 'daemons' : 'queues');
    }
}
