<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\CertificateInstaller;
use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\LoadBalancer;
use Falak\Identity\Contracts\AuditLog;

final class RemoveLoadBalancer
{
    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly CertificateInstaller $certificates,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(LoadBalancer $balancer): void
    {
        $balancer->delete();

        $this->audit->record('edge.load_balancer_removed', 'site', $balancer->site_id, ['server_id' => $balancer->server_id], $balancer->organization_id);

        foreach (Certificate::query()->where('site_id', $balancer->site_id)->get() as $certificate) {
            $this->certificates->sync($certificate);
        }

        $this->changes->siteChanged($balancer->site_id, [$balancer->server_id]);
        SyncCloudflareDns::site($balancer->site_id);
    }
}
