<?php

namespace Kiln\Edge\Application\Actions;

use Illuminate\Validation\ValidationException;
use Kiln\Edge\Application\CertificateInstaller;
use Kiln\Edge\Domain\Models\Certificate;
use Kiln\Edge\Domain\Models\Domain;
use Kiln\Identity\Contracts\AuditLog;

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
