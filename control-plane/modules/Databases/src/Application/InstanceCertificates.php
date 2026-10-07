<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Contracts\ServerCertificates;
use Falak\Servers\Contracts\ServerDirectory;

/**
 * TLS certificates of database containers, issued by the Falak CA (the one agents pin): valid for the instance's DNS
 * name on the environment network, localhost and the server's private addresses. The agent keeps them on the server
 * (/etc/falak/db/<id>/tls) and mounts them read-only; they are only sent when the container is created.
 */
final class InstanceCertificates
{
    public function __construct(
        private readonly ServerCertificates $certificates,
        private readonly ServerDirectory $servers,
    ) {}

    /**
     * @return array{certificate: string, private_key: string, ca: string}
     */
    public function issue(DatabaseInstance $instance): array
    {
        $server = $this->servers->find($instance->server_id);
        $hostnames = array_values(array_unique(array_filter([
            $instance->hostname,
            $instance->container(),
            'localhost',
            '127.0.0.1',
            $server?->privateIpv4,
        ])));

        $issued = $this->certificates->issue($hostnames, (int) config('databases.tls_days', 397));
        $instance->forceFill(['tls_expires_at' => $issued->notAfter])->save();

        return ['certificate' => $issued->certificatePem, 'private_key' => $issued->privateKeyPem, 'ca' => $issued->caPem];
    }
}
