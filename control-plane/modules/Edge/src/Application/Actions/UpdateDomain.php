<?php

namespace Kiln\Edge\Application\Actions;

use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Identity\Contracts\AuditLog;

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
    }
}
