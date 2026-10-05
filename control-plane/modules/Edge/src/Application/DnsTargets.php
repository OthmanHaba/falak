<?php

namespace Falak\Edge\Application;

use Falak\Edge\Contracts\Data\DnsTarget;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * Where a site's domains must point. A load-balanced site: its `lb` server only. Otherwise every server the site
 * runs on (leader first) — several A records are DNS round-robin, without health checks.
 */
final class DnsTargets
{
    public function __construct(private readonly ServerDirectory $servers) {}

    /**
     * @param  list<string>  $serverIds  leader first
     * @return list<DnsTarget>
     */
    public function for(string $organizationId, array $serverIds, ?string $siteId = null): array
    {
        $balancer = $siteId !== null ? LoadBalancer::query()->where('site_id', $siteId)->value('server_id') : null;

        if (is_string($balancer) && ($target = $this->target($organizationId, $balancer, true))) {
            return [$target];
        }

        $targets = [];

        foreach (array_values(array_unique($serverIds)) as $serverId) {
            if ($target = $this->target($organizationId, (string) $serverId, false)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    private function target(string $organizationId, string $serverId, bool $loadBalancer): ?DnsTarget
    {
        $server = $this->servers->find(strtolower($serverId));

        if ($server === null || $server->organizationId !== $organizationId) {
            return null;
        }

        return new DnsTarget($server->id, $server->name, $server->ipv4 ?: null, $server->ipv6 ?: null, $loadBalancer);
    }
}
