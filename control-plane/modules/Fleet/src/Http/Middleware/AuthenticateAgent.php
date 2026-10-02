<?php

namespace Kiln\Fleet\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\Certificate;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * mTLS authentication. The edge verifies the client certificate against the Kiln CA and forwards its
 * SHA-256 fingerprint; the header is only honoured when the TCP peer is a configured trusted proxy.
 * A 401 carries a reason code in `error`: a revoked agent (its server was deleted, or an admin revoked it) gets `agent_revoked`.
 */
final class AuthenticateAgent
{
    public const AGENT = 'fleet.agent';

    public const CERTIFICATE = 'fleet.certificate';

    public function handle(Request $request, Closure $next): Response
    {
        $peer = (string) $request->server('REMOTE_ADDR', '');
        $proxies = (array) config('fleet.trusted_proxies', []);

        if ($peer === '' || $proxies === [] || ! IpUtils::checkIp($peer, $proxies)) {
            return $this->unauthorized('untrusted_peer', 'Client certificate header not accepted from this peer.');
        }

        $fingerprint = strtolower(trim((string) $request->headers->get((string) config('fleet.fingerprint_header'), '')));

        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            return $this->unauthorized('missing_certificate', 'Missing or malformed client certificate fingerprint.');
        }

        /** @var ?Certificate $certificate */
        $certificate = Certificate::query()->with('agent')->where('fingerprint', $fingerprint)->first();
        $agent = $certificate?->agent;

        if (! $certificate || ! $agent instanceof Agent) {
            return $this->unauthorized('unknown_certificate', 'Unknown client certificate.');
        }

        // Revoked agent (its server was deleted, or an admin revoked it): the machine needs a new install command.
        if ($agent->isRevoked()) {
            return $this->unauthorized('agent_revoked', 'This agent was revoked or its server was removed from Kiln. Run a new install command to connect the machine again.');
        }

        if (! $certificate->isUsable()) {
            return $this->unauthorized($certificate->revoked_at !== null ? 'certificate_revoked' : 'certificate_expired', 'Expired or revoked client certificate.');
        }

        if ($certificate->first_used_at === null) {
            $certificate->forceFill(['first_used_at' => now()])->save();

            // First use of a renewed certificate retires the ones it superseded.
            $agent->certificates()
                ->whereKeyNot($certificate->id)
                ->whereNotNull('superseded_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        $request->attributes->set(self::AGENT, $agent);
        $request->attributes->set(self::CERTIFICATE, $certificate);

        return $next($request);
    }

    /**
     * `error` is a stable reason code for the agent (contracts/agent-protocol/README.md); `message` is for people.
     */
    private function unauthorized(string $error, string $message): Response
    {
        return response()->json(['message' => $message, 'error' => $error], 401);
    }
}
