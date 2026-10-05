<?php

namespace Falak\Edge\Application;

use Falak\Edge\Domain\Enums\InstallStatus;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\CertificateInstall;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Illuminate\Support\Str;

/**
 * Converges edge.cert.install on the servers routing a certificate's site.
 */
final class CertificateInstaller
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly EdgeChanges $changes,
        private readonly int $timeoutSeconds = 60,
    ) {}

    /**
     * Install on every server routing the site; remove from servers that no longer do.
     */
    public function sync(Certificate $certificate): void
    {
        $servers = $certificate->site_id ? $this->changes->serversFor($certificate->site_id) : [];
        $installs = $certificate->installs()->get()->keyBy('server_id');

        foreach ($servers as $serverId) {
            $install = $installs->get($serverId);

            if ($install === null || in_array($install->status, [InstallStatus::Failed, InstallStatus::Removing], true)) {
                $this->install($certificate, $serverId, $install);
            }
        }

        foreach ($installs as $serverId => $install) {
            if (! in_array($serverId, $servers, true) && $install->status !== InstallStatus::Removing) {
                $this->uninstall($certificate, $install);
            }
        }
    }

    /** Remove from every server (before deleting the certificate). */
    public function uninstallEverywhere(Certificate $certificate): void
    {
        foreach ($certificate->installs()->get() as $install) {
            $this->uninstall($certificate, $install, deleteRow: true);
        }
    }

    public function install(Certificate $certificate, string $serverId, ?CertificateInstall $install = null): void
    {
        $install ??= new CertificateInstall(['certificate_id' => $certificate->id, 'server_id' => $serverId]);

        $payload = array_filter([
            'name' => $certificate->name,
            'cert_pem' => $certificate->cert_pem,
            'key_pem' => $certificate->key_pem,
            'chain_pem' => $certificate->chain_pem,
            'state' => 'present',
        ], fn ($v) => $v !== null);

        try {
            $handle = $this->agents->dispatch($serverId, 'edge.cert.install', $payload, $this->timeoutSeconds, "edge.cert:{$certificate->id}:{$serverId}:".Str::ulid());
            $install->forceFill(['status' => InstallStatus::Pending, 'command_id' => $handle->id, 'error' => null])->save();
        } catch (AgentUnavailable) {
            $install->forceFill(['status' => InstallStatus::Failed, 'command_id' => null, 'error' => 'The server agent is not connected.'])->save();
        }
    }

    private function uninstall(Certificate $certificate, CertificateInstall $install, bool $deleteRow = false): void
    {
        try {
            $handle = $this->agents->dispatch($install->server_id, 'edge.cert.install', ['name' => $certificate->name, 'state' => 'absent'], $this->timeoutSeconds, "edge.cert:{$certificate->id}:{$install->server_id}:".Str::ulid());
            $install->forceFill(['status' => InstallStatus::Removing, 'command_id' => $handle->id])->save();
        } catch (AgentUnavailable) {
            $deleteRow = true;
        }

        if ($deleteRow) {
            $install->delete();
        }
    }
}
