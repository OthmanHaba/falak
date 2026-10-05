<?php

namespace Falak\Fleet\Http\Controllers\Agent;

use Falak\Fleet\Application\Actions\EnrollAgent;
use Falak\Fleet\Infrastructure\PanelUrls;
use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Falak\Fleet\Infrastructure\Pki\InvalidCsr;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use Falak\Kernel\Http\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
