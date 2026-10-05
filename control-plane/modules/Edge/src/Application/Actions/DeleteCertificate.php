<?php

namespace Falak\Edge\Application\Actions;

use Falak\Edge\Application\CertificateInstaller;
use Falak\Edge\Domain\Models\Certificate;
use Falak\Edge\Domain\Models\Domain;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Validation\ValidationException;

final class DeleteCertificate
{
    public function __construct(
        private readonly CertificateInstaller $installer,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Certificate $certificate): void
    {
        $inUse = Domain::query()->where('certificate_id', $certificate->id)->pluck('name')->all();

        if ($inUse !== []) {
            throw ValidationException::withMessages(['certificate' => 'The certificate is used by '.implode(', ', $inUse).'. Switch those domains to another TLS mode first.']);
        }

        $this->installer->uninstallEverywhere($certificate);
        $certificate->delete();

        $this->audit->record('edge.certificate_deleted', 'site', $certificate->site_id, ['certificate_id' => $certificate->id, 'fingerprint' => $certificate->fingerprint], $certificate->organization_id);
    }
}
