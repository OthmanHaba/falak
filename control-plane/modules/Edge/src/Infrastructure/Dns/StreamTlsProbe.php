<?php

namespace Kiln\Edge\Infrastructure\Dns;

use DateTimeImmutable;

/**
 * TLS handshake to <address>:443 with SNI <name>, verifying the chain against the system trust store.
 */
final class StreamTlsProbe implements TlsProbe
{
    public function __construct(private readonly int $timeoutSeconds = 4) {}

    public function probe(string $name, string $address): array
    {
        $context = stream_context_create(['ssl' => [
            'peer_name' => $name,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'capture_peer_cert' => true,
        ]]);
        $host = str_contains($address, ':') ? "[{$address}]" : $address;
        $socket = @stream_socket_client("ssl://{$host}:443", $errno, $error, $this->timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return [
                'status' => 'pending',
                'message' => 'No trusted certificate yet. The edge requests one from Let\'s Encrypt once DNS points here (port 80 must be reachable); this can take a minute after the first deploy.',
                'issuer' => null,
                'expires_at' => null,
            ];
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        $parsed = $certificate ? openssl_x509_parse($certificate) : false;
        $issuer = is_array($parsed) ? ($parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null) : null;
        $expires = is_array($parsed) && isset($parsed['validTo_time_t']) ? (new DateTimeImmutable('@'.$parsed['validTo_time_t']))->format(DATE_ATOM) : null;

        return [
            'status' => 'issued',
            'message' => 'Certificate issued'.($issuer ? " by {$issuer}" : '').'.',
            'issuer' => is_string($issuer) ? $issuer : null,
            'expires_at' => $expires,
        ];
    }
}
