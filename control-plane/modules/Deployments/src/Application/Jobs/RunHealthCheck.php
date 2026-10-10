<?php

namespace Falak\Deployments\Application\Jobs;

use Falak\Deployments\Application\Health\SiteHealthProbe;
use Falak\Deployments\Application\Orchestration\DeploymentLog;
use Falak\Deployments\Application\Orchestration\Orchestrator;
use Falak\Deployments\Domain\Enums\StepStatus;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\DeploymentStep;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A deployment's health check step on one server ({@see SiteHealthProbe}: through the edge, from the control
 * plane). Retries are delayed re-dispatches — a worker never sleeps waiting.
 */
final class RunHealthCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $stepId, public int $attempt) {}

    public function handle(SiteHealthProbe $probe, SiteDirectory $sites, DeploymentLog $log, Orchestrator $orchestrator): void
    {
        $step = DeploymentStep::query()->with('target')->find($this->stepId);

        if (! $step || $step->status !== StepStatus::Running) {
            return;
        }

        /** @var Deployment $deployment */
        $deployment = Deployment::query()->findOrFail($step->deployment_id);
        $health = (array) $deployment->setting('health', []);
        $retries = max(1, (int) ($health['retries'] ?? 3));

        // A compose stack's bootstrap pass started only the services its split-out sites use (compose up --wait
        // checked them); its public services come with the full stack.
        if (($bootstrap = (array) $deployment->setting('bootstrap', [])) !== []) {
            $names = implode(', ', array_map(fn (string $id) => $sites->find($id)?->name ?? $id, (array) $deployment->setting('awaits_sites', [])));
            $message = 'Bootstrap: started '.implode(', ', $bootstrap)." for {$names}; the full stack follows once {$names} is live.";
            $log->note($deployment->id, "✓ {$message}", $step, 'stdout');
            $orchestrator->healthChecked($step->id, true, $message);

            return;
        }

        [$healthy, $message] = $probe->probe($deployment->site_id, (string) $step->server_id, $health);

        if ($message === SiteHealthProbe::NO_ADDRESS) {
            $orchestrator->healthChecked($step->id, false, $message);

            return;
        }

        $log->note($deployment->id, ($healthy ? '✓ ' : '… ')."{$message} [attempt {$this->attempt}/{$retries}]", $step, $healthy ? 'stdout' : 'stderr');

        if (! $healthy && $this->attempt < $retries) {
            self::dispatch($this->stepId, $this->attempt + 1)->delay(now()->addSeconds(max(0, (int) ($health['retry_delay_s'] ?? 5))));

            return;
        }

        $orchestrator->healthChecked($step->id, $healthy, $message);
    }
}
