<?php

namespace Kiln\Fleet\Http\Controllers\Agent;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Kiln\Fleet\Application\Actions\EnrollAgent;
use Kiln\Fleet\Infrastructure\PanelUrls;
use Kiln\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Kiln\Fleet\Infrastructure\Pki\InvalidCsr;
use Kiln\Fleet\Infrastructure\ProtocolSchemas;
use Kiln\Kernel\Http\Controller;

/**
 * POST /agent/v1/enroll — plain TLS + one-time token (enroll-request → enroll-response).
 */
final class EnrollController extends Controller
{
    use ReadsProtocolDocuments;

    public function __invoke(Request $request, ProtocolSchemas $schemas, EnrollAgent $enroll, CertificateAuthorityService $ca, PanelUrls $urls): JsonResponse
    {
        $document = $this->document($request, $schemas, 'enroll-request.schema.json');

        try {
            ['agent' => $agent, 'certificate' => $certificate] = $enroll($document['token'], $document['csr_pem'], $document['facts'], $request->ip());
        } catch (AuthenticationException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        } catch (InvalidCsr $e) {
            throw ValidationException::withMessages(['csr_pem' => $e->getMessage()]);
        }

        return response()->json([
            'agent_id' => $agent->id,
            'cert_pem' => $certificate->pem,
            'ca_pem' => $ca->caPem(),
            'endpoints' => [
                'api' => $urls->agentApi(),
                'otlp' => (string) config('fleet.otlp_endpoint'),
            ],
        ], 201);
    }
}
