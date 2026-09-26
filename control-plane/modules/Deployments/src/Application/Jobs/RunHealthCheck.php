<?php

namespace Kiln\Deployments\Application\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Queue\InteractsWithQueue;
use Kiln\Deployments\Application\Orchestration\DeploymentLog;
use Kiln\Deployments\Application\Orchestration\Orchestrator;
use Kiln\Deployments\Domain\Enums\StepStatus;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;
use Kiln\Edge\Contracts\Data\DomainData;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\SiteDirectory;
use Throwable;

/**
 * HTTP health check of one server from the control plane: GET https://<primary domain><path>
 * resolved to the server's address (so the edge's certificate and routing are exercised), or
 * http://<ip><path> when the site has no domain. Retries are delayed re-dispatches — a worker never
 * sleeps waiting.
 */
final class RunHealthCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public string $stepId, public int $attempt) {}

    public function handle(HttpFactory $http, SiteDirectory $sites, ServerDirectory $servers, EdgeRoutes $edge, DeploymentLog $log, Orchestrator $orchestrator): void
    {
        $step = DeploymentStep::query()->with('target')->find($this->stepId);

        if (! $step || $step->status !== StepStatus::Running) {
            return;
        }

        /** @var Deployment $deployment */
        $deployment = Deployment::query()->findOrFail($step->deployment_id);
        $health = (array) $deployment->setting('health', []);
        $path = (string) ($health['path'] ?? '/');
        $expect = (int) ($health['status'] ?? 200);
        $timeout = max(1, (int) ($health['timeout_s'] ?? 10));
        $retries = max(1, (int) ($health['retries'] ?? 3));

        $server = $servers->find((string) $step->server_id);
        $ip = $server?->ipv4 ?? $server?->privateIpv4;

        if ($ip === null) {
            $orchestrator->healthChecked($step->id, false, 'The server has no IP address to check.');

            return;
        }

        $host = $this->host($deployment->site_id, $sites, $edge);
        $url = $host !== null ? "https://{$host}{$path}" : "http://{$ip}{$path}";
        $options = $host !== null ? ['curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$ip}"]]] : [];

        $started = microtime(true);

        try {
            $response = $http->timeout($timeout)->connectTimeout(min(5, $timeout))->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'Kiln-HealthCheck/1'])
                ->withOptions($options)
                ->get($url);
            $status = $response->status();
            $healthy = $status === $expect;
            $message = sprintf('GET %s via %s → %d in %d ms (expected %d)', $url, $ip, $status, (int) ((microtime(true) - $started) * 1000), $expect);
        } catch (Throwable $e) {
            $healthy = false;
            $message = sprintf('GET %s via %s failed: %s', $url, $ip, $e->getMessage());
        }

        $log->note($deployment->id, ($healthy ? '✓ ' : '… ')."{$message} [attempt {$this->attempt}/{$retries}]", $step, $healthy ? 'stdout' : 'stderr');

        if (! $healthy && $this->attempt < $retries) {
            self::dispatch($this->stepId, $this->attempt + 1)->delay(now()->addSeconds(max(0, (int) ($health['retry_delay_s'] ?? 5))));

            return;
        }

        $orchestrator->healthChecked($step->id, $healthy, $message);
    }

    private function host(string $siteId, SiteDirectory $sites, EdgeRoutes $edge): ?string
    {
        try {
            $domains = array_values(array_filter($edge->domainsFor($siteId), fn (DomainData $d) => ! $d->isWildcard()));
        } catch (Throwable) {
            $domains = [];
        }

        usort($domains, fn (DomainData $a, DomainData $b) => (int) $b->primary <=> (int) $a->primary);

        return $domains[0]->name ?? $sites->find($siteId)?->testDomain;
    }
}
