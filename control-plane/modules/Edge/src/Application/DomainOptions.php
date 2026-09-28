<?php

namespace Kiln\Edge\Application;

use Illuminate\Validation\ValidationException;
use Kiln\Edge\Contracts\Data\DnsTarget;
use Kiln\Edge\Infrastructure\EloquentSiteDomains;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteDirectory;
use Kiln\Sites\Contracts\TargetRole;

/**
 * What the domain picker offers for a new or existing site: the test domain, a generated name (pointing at the
 * leader, or the load balancer of a load-balanced site) and the DNS targets for a custom domain.
 */
final class DomainOptions
{
    public function __construct(
        private readonly GeneratedDomains $generated,
        private readonly DnsTargets $targets,
        private readonly EloquentSiteDomains $siteDomains,
        private readonly SiteDirectory $sites,
    ) {}

    /**
     * @param  list<string>  $serverIds
     * @return array<string, mixed>
     */
    public function for(string $organizationId, array $serverIds, ?string $siteId = null): array
    {
        $targets = $this->targets->for($organizationId, $serverIds, $siteId);
        $suffix = $this->generated->suffix($organizationId);
        $pointsAt = $targets[0] ?? null;

        return [
            'test_domain' => EloquentSiteDomains::testDomainBase(),
            'generated' => [
                'suffix' => $suffix,
                'provider' => $suffix,
                'ipv4' => $pointsAt?->ipv4,
                'target' => $pointsAt?->toArray(),
                'available' => $suffix !== null && $pointsAt?->ipv4 !== null,
                'reason' => match (true) {
                    $suffix === null => 'Generated domains are turned off for this organization (Settings → Domains).',
                    $pointsAt === null => 'Pick a server first.',
                    $pointsAt->ipv4 === null => "{$pointsAt->name} has no public IPv4 address yet.",
                    default => null,
                },
            ],
            'default' => $this->siteDomains->defaultType($organizationId)->value,
            'targets' => array_map(fn (DnsTarget $target) => $target->toArray(), $targets),
        ];
    }

    /**
     * The site's servers (leader first) when $siteId is given, else the requested ones.
     *
     * @param  list<string>  $serverIds
     * @return array{0: list<string>, 1: ?SiteData}
     *
     * @throws ValidationException
     */
    public function servers(string $organizationId, array $serverIds, ?string $siteId): array
    {
        if ($siteId === null || $siteId === '') {
            return [array_values(array_map('strval', $serverIds)), null];
        }

        $site = $this->sites->find(strtolower($siteId));

        if ($site === null || $site->organizationId !== $organizationId) {
            throw ValidationException::withMessages(['site' => 'Unknown site.']);
        }

        $targets = $site->targets;
        usort($targets, fn ($a, $b) => ($b->role === TargetRole::Leader) <=> ($a->role === TargetRole::Leader));

        return [array_map(fn ($target) => $target->serverId, $targets), $site];
    }
}
