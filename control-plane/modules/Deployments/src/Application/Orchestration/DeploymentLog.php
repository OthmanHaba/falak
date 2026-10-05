<?php

namespace Falak\Deployments\Application\Orchestration;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Deployments\Domain\Models\DeploymentTarget;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Deployments\Events\DeploymentOutputReceived;
use Throwable;

/**
 * Deployment output: orchestration notes, agent command output and build output, in one ordered
 * stream per deployment (the row id is the `seq` cursor).
 */
final class DeploymentLog
{
    public function note(string $deploymentId, string $message, ?DeploymentStep $step = null, string $stream = 'stdout'): void
    {
        $target = $step?->target;

        $this->insert($deploymentId, [[
            'step_id' => $step?->id,
            'server_id' => $step?->server_id,
            'server_name' => $target instanceof DeploymentTarget ? $target->server_name : null,
            'phase' => $step?->phase,
            'stream' => $stream,
            'data' => rtrim($message, "\n")."\n",
            'source_seq' => null,
            'at' => now(),
        ]]);
    }

    /**
     * Agent / builder output for a step; idempotent on the source seq.
     *
     * @param  list<array{seq: int, stream: ?string, data: ?string, at: ?string}>  $events
     */
    public function stepOutput(DeploymentStep $step, ?string $serverName, array $events): void
    {
        $rows = [];

        foreach ($events as $event) {
            if (($event['data'] ?? null) === null || $event['data'] === '') {
                continue;
            }

            $rows[] = [
                'step_id' => $step->id,
                'server_id' => $step->server_id,
                'server_name' => $serverName,
                'phase' => $step->phase,
                'stream' => ($event['stream'] ?? 'stdout') === 'stderr' ? 'stderr' : 'stdout',
                'data' => $event['data'],
                'source_seq' => $event['seq'],
                'at' => self::time($event['at'] ?? null),
            ];
        }

        $this->insert($step->deployment_id, $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $deploymentId, array $rows): void
    {
        $lines = [];

        foreach ($rows as $row) {
            try {
                $lines[] = OutputLine::query()->create(['deployment_id' => $deploymentId, ...$row])->toLine();
            } catch (UniqueConstraintViolationException) {
                // Re-delivered output.
            }
        }

        if ($lines !== []) {
            DeploymentOutputReceived::dispatch($deploymentId, $lines);
        }
    }

    private static function time(?string $at): Carbon
    {
        try {
            return $at ? Carbon::parse($at) : now();
        } catch (Throwable) {
            return now();
        }
    }
}
