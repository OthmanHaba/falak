<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Kiln\Edge\Application\CertificateInstaller;
use Kiln\Edge\Domain\Certificates\PemCertificate;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;
use SensitiveParameter;

final class UploadCertificate
{
    public function __construct(
        private readonly CertificateInstaller $installer,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(SiteData $site, string $certPem, #[SensitiveParameter] string $keyPem, ?string $chainPem, ?string $userId): Certificate
    {
        try {
            $pem = PemCertificate::parse($certPem, $keyPem, $chainPem);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }

        if (Certificate::query()->where('site_id', $site->id)->where('fingerprint', $pem->fingerprint)->exists()) {
            throw ValidationException::withMessages(['certificate' => 'This certificate has already been uploaded.']);
        }

        $certificate = Certificate::query()->create([
            'organization_id' => $site->organizationId,
            'site_id' => $site->id,
            'name' => 'kiln-'.strtolower((string) Str::ulid()),
            'domains' => $pem->domains,
            'cert_pem' => $pem->certPem,
            'key_pem' => $pem->keyPem,
            'chain_pem' => $pem->chainPem,
            'issuer' => $pem->issuer,
            'not_before' => $pem->notBefore,
            'not_after' => $pem->notAfter,
            'fingerprint' => $pem->fingerprint,
            'created_by' => $userId,
        ]);

        $this->audit->record('edge.certificate_uploaded', 'site', $site->id, ['certificate_id' => $certificate->id, 'domains' => $pem->domains, 'fingerprint' => $pem->fingerprint], $site->organizationId);

        $this->installer->sync($certificate);

        return $certificate;
    }
}
