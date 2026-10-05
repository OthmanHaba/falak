<?php

namespace Falak\Edge\Application\Actions;

use Illuminate\Validation\ValidationException;
use Falak\Edge\Application\CertificateInstaller;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Domain\Enums\LbPolicy;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerType;
use Falak\Sites\Contracts\Data\SiteData;

final class ConfigureLoadBalancer
{
    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly EdgeChanges $changes,
        private readonly CertificateInstaller $certificates,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, int>  $weights  target server id => weight
     */
    public function __invoke(SiteData $site, string $serverId, LbPolicy $policy, ?string $healthUri, int $backendPort, array $weights): LoadBalancer
    {
        $server = $this->servers->find($serverId);

        if ($server === null || $server->organizationId !== $site->organizationId || $server->type !== ServerType::LoadBalancer) {
            throw ValidationException::withMessages(['server_id' => 'Choose a load balancer server of this organization.']);
        }

        if (in_array($serverId, $site->serverIds(), true)) {
            throw ValidationException::withMessages(['server_id' => 'The load balancer cannot also be a target of the site.']);
        }

        $targets = $site->serverIds();
        $weights = array_intersect_key(array_map(fn ($w) => max(1, min(LoadBalancer::MAX_WEIGHT, (int) $w)), $weights), array_flip($targets));

        $existing = LoadBalancer::query()->where('site_id', $site->id)->first();
        $previousServer = $existing?->server_id;

        $balancer = $existing ?? new LoadBalancer(['site_id' => $site->id, 'organization_id' => $site->organizationId]);
        $balancer->forceFill([
            'server_id' => $serverId,
            'policy' => $policy,
            'health_uri' => $healthUri ?: null,
            'backend_port' => $backendPort,
            'weights' => $weights,
        ])->save();

        $this->audit->record('edge.load_balancer_configured', 'site', $site->id, ['server_id' => $serverId, 'policy' => $policy->value, 'weights' => $weights], $site->organizationId);

        foreach (Certificate::query()->where('site_id', $site->id)->get() as $certificate) {
            $this->certificates->sync($certificate);
        }

        $this->changes->siteChanged($site->id, array_values(array_filter([$previousServer])));
        SyncCloudflareDns::site($site->id); // records now point at the load balancer

        return $balancer;
    }
}
