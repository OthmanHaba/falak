<?php

namespace Kiln\Edge\Application\Actions;

use Kiln\Edge\Application\CertificateInstaller;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Application\Jobs\SyncCloudflareDns;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\LoadBalancer;
use Kiln\Identity\Contracts\AuditLog;

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
