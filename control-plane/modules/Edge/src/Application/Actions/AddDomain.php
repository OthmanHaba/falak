<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Edge\Application\EdgeChanges;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Edge\Events\DomainAdded;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;

final class AddDomain
{
    use ValidatesDomainTls;

    public function __construct(
        private readonly EdgeChanges $changes,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(SiteData $site, string $name, TlsMode $tls = TlsMode::Auto, WwwRedirect $www = WwwRedirect::None, ?string $certificateId = null, ?string $dnsCredentialId = null): Domain
    {
        $name = strtolower(rtrim(trim($name), '.'));

        if (preg_match(Domain::HOSTNAME, $name) !== 1 || strlen($name) > 253) {
            throw ValidationException::withMessages(['name' => 'Enter a valid domain name, e.g. example.com or *.example.com.']);
        }

        if ($site->testDomain !== null && strtolower($site->testDomain) === $name) {
            throw ValidationException::withMessages(['name' => 'This is the site\'s test domain; it is always routed.']);
        }

        $this->validateDomainTls($site->organizationId, $site->id, $name, $tls, $www, $certificateId, $dnsCredentialId);
        $this->ensureHostsAvailable($name, $www);

        $domain = DB::transaction(function () use ($site, $name, $tls, $www, $certificateId, $dnsCredentialId) {
            $primary = ! Domain::query()->where('site_id', $site->id)->lockForUpdate()->exists();

            return Domain::query()->create([
                'organization_id' => $site->organizationId,
                'site_id' => $site->id,
                'name' => $name,
                'is_primary' => $primary,
                'www_redirect' => $www,
                'tls_mode' => $tls,
                'certificate_id' => $tls === TlsMode::Custom ? $certificateId : null,
                'dns_credential_id' => $tls === TlsMode::Dns ? $dnsCredentialId : null,
            ]);
        });

        $this->audit->record('edge.domain_added', 'site', $site->id, ['domain' => $name, 'tls' => $tls->value], $site->organizationId);

        DomainAdded::dispatch($domain->id, $site->id, $site->organizationId, $name, $domain->is_primary);
        $this->changes->siteChanged($site->id);

        return $domain;
    }
}
