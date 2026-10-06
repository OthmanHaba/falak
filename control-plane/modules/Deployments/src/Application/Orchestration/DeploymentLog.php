<?php

namespace Falak\Deployments\Application\Orchestration;

use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Deployments\Domain\Models\DeploymentTarget;
use Falak\Deployments\Domain\Models\OutputLine;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Events\DeploymentOutputReceived;
use Falak\Kernel\Support\SecretMask;
use Falak\Sites\Contracts\SecretVariables;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Deployment output: orchestration notes, agent command output and build output, in one ordered
 * stream per deployment (the row id is the `seq` cursor). The site's secret values are masked before anything is
 * stored or broadcast (the agent masks them already; this is the second pass).
 */
final class DeploymentLog
{
    /** Seconds a deployment's mask is reused (output arrives in many small batches). */
    private const MASK_TTL = 10;

    /** @var array<string, array{0: SecretMask, 1: int}> deployment id => [mask, expires] */
    private static array $masks = [];

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SecretVariables $secrets,
    ) {}

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
        $mask = $rows !== [] ? $this->mask($deploymentId) : null;

        foreach ($rows as $row) {
            if ($mask !== null && is_string($row['data'] ?? null)) {
                $row['data'] = $mask->apply($row['data']);
            }

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

    /**
     * The deployment's secret values: the variables its release was written with (once prepared) and the site's stored
     * ones (build output comes earlier), restricted to the secret names.
     */
    private function mask(string $deploymentId): SecretMask
    {
        $cached = self::$masks[$deploymentId] ?? null;

        if ($cached !== null && $cached[1] >= time()) {
            return $cached[0];
        }

        $deployment = Deployment::query()->find($deploymentId);
        $stored = $deployment !== null ? ($this->sites->environment($deployment->site_id)?->variables ?? []) : [];
        $released = $deployment?->release_id !== null ? (Release::query()->find($deployment->release_id)?->environment ?? []) : [];
        $values = [];

        foreach ($this->secrets->names([...$stored, ...$released]) as $name) {
            $values[] = $released[$name] ?? null;
            // An unresolved reference is not the value.
            $values[] = isset($stored[$name]) && ! str_contains((string) $stored[$name], '${{') ? $stored[$name] : null;
        }

        $mask = new SecretMask(array_filter($values, fn ($value) => $value !== null));

        if (count(self::$masks) >= 64) {
            self::$masks = [];
        }

        self::$masks[$deploymentId] = [$mask, time() + self::MASK_TTL];

        return $mask;
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
