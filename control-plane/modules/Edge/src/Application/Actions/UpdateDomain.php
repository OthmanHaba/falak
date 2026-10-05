<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\EdgeChanges;
use Falak\Edge\Application\Jobs\SyncCloudflareDns;
use Falak\Edge\Contracts\TlsMode;
use Falak\Edge\Domain\Enums\WwwRedirect;
use Falak\Edge\Domain\Models\Domain;
use Falak\Identity\Contracts\AuditLog;

final class UpdateDomain
{
    use ValidatesDomainTls;

    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Domain $domain, TlsMode $tls, WwwRedirect $www, ?string $certificateId, ?string $dnsCredentialId): void
    {
        $this->validateDomainTls($domain->organization_id, $domain->site_id, $domain->name, $tls, $www, $certificateId, $dnsCredentialId);
        $this->ensureHostsAvailable($domain->name, $www, $domain->id, 'www_redirect');

        $domain->forceFill([
            'tls_mode' => $tls,
            'www_redirect' => $www,
            'certificate_id' => $tls === TlsMode::Custom ? $certificateId : null,
            'dns_credential_id' => $tls === TlsMode::Dns ? $dnsCredentialId : null,
        ])->save();

        $this->audit->record('edge.domain_updated', 'site', $domain->site_id, ['domain' => $domain->name, 'tls' => $tls->value, 'www_redirect' => $www->value], $domain->organization_id);
        $this->changes->siteChanged($domain->site_id);
        SyncCloudflareDns::domain($domain->id); // the www host may have appeared or gone
    }
}
