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
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\SiteDirectory;
use Throwable;

/**
 * HTTP health check of one server from the control plane: GET https://<primary domain><path>
 * resolved to the server's address (so the edge's certificate and routing are exercised), or
 * http://<ip><path> when the site has no domain. Retries are delayed re-dispatches — a worker never
 * sleeps waiting.
 *
 * When no HTTP answer comes back through the primary domain (TLS handshake or connection error), the
 * site's other domains are tried, then plain HTTP: a server of a multi-server site may only hold a
 * certificate for the name that points at it (per-server DNS), and load-balancer backends serve plain
 * HTTP. The first HTTP answer decides; an application error (e.g. 500) is never skipped over.
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

        $site = $sites->find($deployment->site_id);
        $checks = [];

        if ($site?->compose !== null) {
            // Compose: every public service through the edge; the primary answers the configured check.
            $publicServices = $site->compose->publicServices;

            if ($publicServices === []) {
                $orchestrator->healthChecked($step->id, true, 'No public services to check; every container passed `docker compose up --wait`.');

                return;
            }

            foreach ($publicServices as $i => $public) {
                if ($i === 0) {
                    $host = $this->candidates($deployment->site_id, $sites, $edge);
                    // Container health is verified by `up --wait`; through the edge a redirect (e.g. to a login page)
                    // also proves the route works unless a specific status is configured.
                    $checks[] = $expect === 200
                        ? [$host, null, $path, fn (int $status) => $status >= 200 && $status < 400, 'expected 2xx/3xx', $public->service]
                        : [$host, null, $path, fn (int $status) => $status === $expect, "expected {$expect}", $public->service];

                    continue;
                }

                $host = $public->domain ?? $public->testDomain;

                if ($host === null) {
                    continue; // not routed
                }

                $checks[] = [[[$host, $public->domain !== null ? TlsMode::Auto : $edge->testDomainTls()]], null, '/', fn (int $status) => $status < 500, 'expected < 500', $public->service];
            }
        } else {
            $checks[] = [$this->candidates($deployment->site_id, $sites, $edge), null, $path, fn (int $status) => $status === $expect, "expected {$expect}", null];
        }

        $healthy = true;
        $messages = [];

        foreach ($checks as [$candidates, , $checkPath, $accepts, $expectation, $service]) {
            [$ok, $message] = $this->checkAny($http, $ip, $candidates, $checkPath, $timeout, $accepts, $expectation);
            $healthy = $healthy && $ok;
            $messages[] = ($service !== null && count($checks) > 1 ? "[{$service}] " : '').$message;
        }

        $message = implode('; ', $messages);

        $log->note($deployment->id, ($healthy ? '✓ ' : '… ')."{$message} [attempt {$this->attempt}/{$retries}]", $step, $healthy ? 'stdout' : 'stderr');

        if (! $healthy && $this->attempt < $retries) {
            self::dispatch($this->stepId, $this->attempt + 1)->delay(now()->addSeconds(max(0, (int) ($health['retry_delay_s'] ?? 5))));

            return;
        }

        $orchestrator->healthChecked($step->id, $healthy, $message);
    }

    /**
     * Tries the candidates in order until one gets an HTTP answer (see the class docblock).
     *
     * @param  list<array{0: ?string, 1: TlsMode}>  $candidates  host (null = the server IP) and its TLS mode
     * @param  callable(int): bool  $accepts
     * @return array{0: bool, 1: string}
     */
    private function checkAny(HttpFactory $http, string $ip, array $candidates, string $path, int $timeout, callable $accepts, string $expectation): array
    {
        $failures = [];

        foreach ($candidates as [$host, $tls]) {
            [$ok, $message, $answered] = $this->check($http, $ip, $host, $tls, $path, $timeout, $accepts, $expectation);

            if ($answered) {
                return [$ok, $failures === [] ? $message : $message.' — after: '.implode('; ', $failures)];
            }

            $failures[] = $message;
        }

        return [false, implode('; ', $failures)];
    }

    /**
     * @param  callable(int): bool  $accepts
     * @return array{0: bool, 1: string, 2: bool} healthy, message, whether an HTTP answer came back
     */
    private function check(HttpFactory $http, string $ip, ?string $host, TlsMode $tls, string $path, int $timeout, callable $accepts, string $expectation): array
    {
        $https = $host !== null && $tls !== TlsMode::Off;
        $url = $host === null ? "http://{$ip}{$path}" : ($https ? 'https' : 'http')."://{$host}{$path}";
        $options = $host === null ? [] : [
            'curl' => [CURLOPT_RESOLVE => ["{$host}:".($https ? 443 : 80).":{$ip}"]],
            // Liveness through the edge, not certificate validation: only publicly trusted certificates
            // (ACME) are verifiable from here; internal-CA and uploaded ones may chain to a private root.
            'verify' => $tls->publiclyTrusted(),
        ];

        $started = microtime(true);

        try {
            $response = $http->timeout($timeout)->connectTimeout(min(5, $timeout))->withoutRedirecting()
                ->withHeaders(['User-Agent' => 'Kiln-HealthCheck/1'])
                ->withOptions($options)
                ->get($url);
            $status = $response->status();

            return [$accepts($status), sprintf('GET %s via %s → %d in %d ms (%s)', $url, $ip, $status, (int) ((microtime(true) - $started) * 1000), $expectation), true];
        } catch (Throwable $e) {
            return [false, sprintf('GET %s via %s failed: %s', $url, $ip, $e->getMessage()), false];
        }
    }

    /**
     * What to check the site through, in order: its domains (primary first), then the test domain, then plain
     * HTTP on the primary name when it was tried over HTTPS. `[[null, Off]]` (the server IP) without any name.
     *
     * @return list<array{0: ?string, 1: TlsMode}>
     */
    private function candidates(string $siteId, SiteDirectory $sites, EdgeRoutes $edge): array
    {
        try {
            $domains = array_values(array_filter($edge->domainsFor($siteId), fn (DomainData $d) => ! $d->isWildcard()));
        } catch (Throwable) {
            $domains = [];
        }

        usort($domains, fn (DomainData $a, DomainData $b) => (int) $b->primary <=> (int) $a->primary);

        $candidates = array_map(fn (DomainData $d) => [$d->name, $d->tls], $domains);
        $test = $sites->find($siteId)?->testDomain;

        if ($test !== null) {
            $candidates[] = [$test, $edge->testDomainTls()];
        }

        if ($candidates === []) {
            return [[null, TlsMode::Off]];
        }

        if ($candidates[0][1] !== TlsMode::Off) {
            $candidates[] = [$candidates[0][0], TlsMode::Off];
        }

        return $candidates;
    }
}
