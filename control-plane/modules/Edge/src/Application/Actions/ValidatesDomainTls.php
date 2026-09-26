<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Edge\Contracts\TlsMode;
use Kiln\Edge\Domain\Enums\WwwRedirect;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\DnsCredential;
use Kiln\Edge\Domain\Models\Domain;

trait ValidatesDomainTls
{
    /**
     * Hosts (served + www redirect) must not be claimed by any other domain, in any organization.
     *
     * @throws ValidationException
     */
    private function ensureHostsAvailable(string $name, WwwRedirect $www, ?string $ignoreId = null, string $field = 'name'): void
    {
        $hosts = (new Domain(['name' => $name, 'www_redirect' => $www]))->hosts();
        $apexes = array_values(array_unique(array_map(fn (string $host) => (string) preg_replace('/^www\./', '', $host), $hosts)));

        $clash = Domain::query()
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->where(fn ($q) => $q->whereIn('name', $hosts)->orWhere(fn ($q) => $q->whereIn('name', $apexes)->where('www_redirect', '!=', WwwRedirect::None->value)))
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages([$field => count($hosts) > 1 ? 'This domain or its www counterpart is already in use.' : 'This domain is already in use.']);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateDomainTls(string $organizationId, string $siteId, string $name, TlsMode $tls, WwwRedirect $www, ?string $certificateId, ?string $dnsCredentialId): void
    {
        $wildcard = str_starts_with($name, '*.');

        if ($wildcard && ! in_array($tls, [TlsMode::Dns, TlsMode::Custom, TlsMode::Internal, TlsMode::Off], true)) {
            throw ValidationException::withMessages(['tls_mode' => 'Wildcard domains need a DNS-01 challenge or a custom certificate.']);
        }

        if ($www !== WwwRedirect::None && ($wildcard || str_starts_with($name, 'www.'))) {
            throw ValidationException::withMessages(['www_redirect' => 'Set up www redirects on the apex domain (e.g. example.com).']);
        }

        if ($tls === TlsMode::Custom) {
            $certificate = $certificateId ? Certificate::query()->where('organization_id', $organizationId)->where('site_id', $siteId)->find($certificateId) : null;

            if ($certificate === null) {
                throw ValidationException::withMessages(['certificate_id' => 'Choose an uploaded certificate for this site.']);
            }

            $hosts = (new Domain(['name' => $name, 'www_redirect' => $www]))->hosts();

            foreach ($hosts as $host) {
                if (! $certificate->covers($host)) {
                    throw ValidationException::withMessages(['certificate_id' => "The certificate does not cover {$host}."]);
                }
            }
        }

        if ($tls === TlsMode::Dns && ($dnsCredentialId === null || ! DnsCredential::query()->where('organization_id', $organizationId)->whereKey($dnsCredentialId)->exists())) {
            throw ValidationException::withMessages(['dns_credential_id' => 'Choose a DNS provider credential for the DNS-01 challenge.']);
        }
    }
}
