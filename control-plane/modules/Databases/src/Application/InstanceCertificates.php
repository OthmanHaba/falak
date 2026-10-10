<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Contracts\ServerCertificates;
use Falak\Network\Contracts\PrivateNetwork;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * TLS certificates of database containers, issued by the Falak CA (the one agents pin): valid for the DNS name apps use
 * (which a major upgrade's new instance takes over), the container's own name, localhost and the server's private and
 * WireGuard addresses. The agent keeps them on the server (/etc/falak/db/<id>/tls), mounted read-only; a new one
 * (renewal, a takeover, new addresses) restarts the engine with it.
 */
final class InstanceCertificates
{
    public function __construct(
        private readonly ServerCertificates $certificates,
        private readonly ServerDirectory $servers,
        private readonly PrivateNetwork $network,
    ) {}

    /**
     * @return list<string>
     */
    public function hostnames(DatabaseInstance $instance): array
    {
        $addresses = array_map(fn ($membership) => $membership->address, $this->network->networksOf($instance->server_id));

        return array_values(array_unique(array_filter([
            $instance->hostname,
            $instance->container(),
            'localhost',
            '127.0.0.1',
            $this->servers->find($instance->server_id)?->privateIpv4,
            ...$addresses,
        ])));
    }

    /** The certificate expires within databases.tls_renew_days, or no longer covers the instance's names. */
    public function due(DatabaseInstance $instance): bool
    {
        if ($instance->tls_expires_at === null || $instance->tls_expires_at->lte(now()->addDays((int) config('databases.tls_renew_days', 30)))) {
            return true;
        }

        $want = $this->hostnames($instance);
        $have = (array) ($instance->tls_hostnames ?? []);
        sort($want);
        sort($have);

        return $want !== $have;
    }

    /**
     * @return array{certificate: string, private_key: string, ca: string}
     */
    public function issue(DatabaseInstance $instance): array
    {
        $hostnames = $this->hostnames($instance);
        $issued = $this->certificates->issue($hostnames, (int) config('databases.tls_days', 397));
        $instance->forceFill(['tls_expires_at' => $issued->notAfter, 'tls_hostnames' => $hostnames])->save();

        return ['certificate' => $issued->certificatePem, 'private_key' => $issued->privateKeyPem, 'ca' => $issued->caPem];
    }
}
