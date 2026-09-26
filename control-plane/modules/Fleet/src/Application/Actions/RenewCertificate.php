<?php

namespace Kiln\Fleet\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\Certificate;
use Kiln\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Kiln\Fleet\Infrastructure\Pki\IssuedCertificate;

/**
 * Issues a fresh certificate for an authenticated agent. The presenting certificate stays valid until the
 * agent's first request with the new one (then it is revoked), so a failed swap on the host is recoverable.
 */
final class RenewCertificate
{
    public function __construct(private readonly CertificateAuthorityService $ca) {}

    public function __invoke(Agent $agent, Certificate $presented, string $csrPem): IssuedCertificate
    {
        $certificate = $this->ca->signAgentCsr($csrPem, $agent->id, $agent->organization_id);

        DB::transaction(function () use ($agent, $presented, $certificate) {
            $agent->certificates()->create([
                'serial' => $certificate->serial,
                'fingerprint' => $certificate->fingerprint,
                'certificate_pem' => $certificate->pem,
                'not_before' => $certificate->notBefore,
                'not_after' => $certificate->notAfter,
            ]);

            $presented->forceFill(['superseded_at' => now()])->save();
        });

        return $certificate;
    }
}
