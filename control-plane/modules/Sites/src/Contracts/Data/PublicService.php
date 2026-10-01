<?php

namespace Kiln\Sites\Contracts\Data;

/**
 * A compose service Kiln publishes on 127.0.0.1:<hostPort> and routes through the edge.
 */
final readonly class PublicService
{
    /**
     * @param  string  $service  compose service name
     * @param  int  $port  container port
     * @param  ?string  $domain  custom domain routed to this service (null = test domain only)
     * @param  ?int  $hostPort  loopback port allocated by Kiln (unique per server)
     * @param  ?string  $testDomain  <slug>.<KILN_TEST_DOMAIN> for the first service, <service>-<slug>.<KILN_TEST_DOMAIN> for the others
     * @param  ?string  $healthCheckPath  path the deploy health check requests through the edge (null: the site's
     *                                    check path for the first service, `/` accepting any answer below 500 for the others)
     */
    public function __construct(
        public string $service,
        public int $port,
        public ?string $domain = null,
        public ?int $hostPort = null,
        public ?string $testDomain = null,
        public ?string $healthCheckPath = null,
    ) {}

    /** Public https URL (custom domain first). */
    public function url(): ?string
    {
        $host = $this->domain ?? $this->testDomain;

        return $host !== null ? "https://{$host}" : null;
    }

    /**
     * @return array{service: string, port: int, domain: ?string, host_port: ?int, test_domain: ?string, url: ?string, health_check_path: ?string}
     */
    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'port' => $this->port,
            'domain' => $this->domain,
            'host_port' => $this->hostPort,
            'test_domain' => $this->testDomain,
            'url' => $this->url(),
            'health_check_path' => $this->healthCheckPath,
        ];
    }
}
