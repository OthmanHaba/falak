<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Falak\Fleet\Application\Actions\RenewCertificate;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Fleet\Http\Middleware\AuthenticateAgent;
use Falak\Fleet\Infrastructure\Pki\InvalidCsr;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Kernel\Http\Controller;

/**
 * POST /agent/v1/renew — { csr_pem } → { cert_pem }, authenticated by the current certificate.
 */
final class RenewController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, ProtocolSchemas $schemas, RenewCertificate $renew): JsonResponse
    {
        $document = $this->document($request, $schemas, null);

        if (! isset($document['csr_pem']) || ! is_string($document['csr_pem']) || $document['csr_pem'] === '' || count($document) !== 1) {
            throw ValidationException::withMessages(['csr_pem' => 'The body must be exactly { "csr_pem": "<PEM CSR>" }.']);
        }

        /** @var Certificate $presented */
        $presented = $request->attributes->get(AuthenticateAgent::CERTIFICATE);

        try {
            $certificate = $renew($this->agent($request), $presented, $document['csr_pem']);
        } catch (InvalidCsr $e) {
            throw ValidationException::withMessages(['csr_pem' => $e->getMessage()]);
        }

        return response()->json(['cert_pem' => $certificate->pem]);
    }
}
