<?php

namespace Falak\Processes\Application;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Data\CommandHandle;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Contracts\Exceptions\InvalidCommandPayload;
use Falak\Processes\Application\Jobs\ConvergeServer;
use Falak\Processes\Domain\Enums\ApplyStatus;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Infrastructure\PayloadHash;
use Falak\Processes\Infrastructure\StateCompiler;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends a server its full desired proc.apply and cron.apply state. Each payload is only dispatched when
 * it differs from the last one that is pending or applied (a failed or undeliverable apply is retried).
 */
final class ServerConverger
{
    public function __construct(
        private readonly StateCompiler $compiler,
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * Debounced: one queued convergence per server for a burst of changes.
     */
    public function schedule(string ...$serverIds): void
    {
        foreach (array_unique(array_filter($serverIds)) as $serverId) {
            ConvergeServer::dispatch($serverId)->delay(now()->addSeconds((int) config('processes.apply_delay_seconds', 2)));
        }
    }

    /**
     * @return array{proc: ?CommandHandle, cron: ?CommandHandle}
     */
    public function converge(string $serverId, bool $force = false): array
    {
        $server = $this->servers->find($serverId);

        if ($server === null) {
            return ['proc' => null, 'cron' => null];
        }

        $compiled = $this->compiler->compile($serverId);
        $state = ServerState::query()->find($serverId) ?? new ServerState(['server_id' => $serverId]);
        $state->organization_id = $server->organizationId;

        $proc = $this->apply($state, 'proc', 'proc.apply', $compiled->procPayload(), $compiled->programMeta, $compiled->programs === [], $force);
        $cron = $this->apply($state, 'cron', 'cron.apply', $compiled->cronPayload(), $compiled->jobMeta, $compiled->jobs === [], $force);

        if ($state->isDirty()) {
            $state->save();
        }

        return ['proc' => $proc, 'cron' => $cron];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<string, mixed>>  $meta
     */
    private function apply(ServerState $state, string $prefix, string $type, array $payload, array $meta, bool $empty, bool $force): ?CommandHandle
    {
        $sha = PayloadHash::of($payload);
        $current = $state->getAttribute("{$prefix}_sha256");
        $status = $state->getAttribute("{$prefix}_status");
        $metaColumn = $prefix === 'proc' ? 'programs' : 'jobs';

        // Never managed and nothing to run: leave the host alone.
        if (! $force && $current === null && $empty) {
            return null;
        }

        if (! $force && $current === $sha && in_array($status, [ApplyStatus::Pending, ApplyStatus::Applied], true)) {
            return null;
        }

        $state->forceFill(["{$prefix}_sha256" => $sha, $metaColumn => $meta]);

        try {
            $handle = $this->agents->dispatch($state->server_id, $type, $payload, (int) config('processes.apply_timeout_seconds', 120), "processes.{$prefix}:{$state->server_id}:".Str::ulid());
        } catch (AgentUnavailable) {
            $state->forceFill(["{$prefix}_status" => ApplyStatus::Error, "{$prefix}_command_id" => null, "{$prefix}_error" => 'The server agent is not connected.']);

            return null;
        } catch (InvalidCommandPayload $e) {
            Log::error("processes: compiled an invalid {$type} payload", ['server_id' => $state->server_id, 'error' => $e->getMessage()]);
            $state->forceFill(["{$prefix}_status" => ApplyStatus::Error, "{$prefix}_command_id" => null, "{$prefix}_error" => Str::limit('Compiled state is invalid: '.$e->getMessage(), 990)]);

            return null;
        }

        $state->forceFill([
            "{$prefix}_status" => ApplyStatus::Pending,
            "{$prefix}_command_id" => $handle->id,
            "{$prefix}_error" => null,
            "{$prefix}_dispatched_at" => now(),
        ]);

        return $handle;
    }
}
