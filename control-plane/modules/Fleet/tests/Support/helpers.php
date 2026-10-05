<?php

use Illuminate\Testing\TestResponse;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Fleet\Infrastructure\ProtocolSchemas;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use phpseclib3\File\X509;

/**
 * @return array{0: string, 1: PrivateKey} [csr pem, private key]
 */
function fleet_csr(string $cn = 'web-1', string $algorithm = 'ec'): array
{
    $key = $algorithm === 'rsa' ? RSA::createKey(2048) : EC::createKey($algorithm === 'ec' ? 'nistp256' : $algorithm);
    $x509 = new X509;
    $x509->setPrivateKey($key);
    $x509->setDNProp('id-at-commonName', $cn);

    return [$x509->saveCSR($x509->signCSR()), $key];
}

/**
 * @return array<string, mixed> facts.schema.json document
 */
function fleet_facts(array $overrides = []): array
{
    return array_replace([
        'hostname' => 'web-1',
        'os' => ['id' => 'ubuntu', 'version' => '24.04'],
        'arch' => 'amd64',
        'kernel' => '6.8.0-45-generic',
        'cpus' => 4,
        'memory_bytes' => 8 * 1024 ** 3,
        'disk_bytes' => 160 * 1024 ** 3,
        'public_ipv4' => '203.0.113.10',
        'private_ipv4' => '10.0.0.2',
        'docker' => null,
        'runtimes' => ['php' => ['8.4'], 'node' => ['22']],
        'agent_version' => '1.0.0',
    ], $overrides);
}

/**
 * @return array<string, mixed> heartbeat.schema.json document
 */
function fleet_heartbeat(array $overrides = []): array
{
    return array_replace([
        'at' => now()->toIso8601ZuluString(),
        'uptime_s' => 3600,
        'load' => [0.25, 0.2, 0.1],
        'cpu_percent' => 12.5,
        'memory_used_bytes' => 2 * 1024 ** 3,
        'disk_used_bytes' => 20 * 1024 ** 3,
        'running_commands' => [],
    ], $overrides);
}

/**
 * Enroll a fake agent through the real endpoint.
 *
 * @return array{agent: Agent, certificate: Certificate, fingerprint: string, response: TestResponse}
 */
function fleet_enroll(string $organizationId, ?string $serverId, array $facts = []): array
{
    $install = app(Enrollment::class)->issueInstallToken($organizationId, $serverId);
    [$csr] = fleet_csr();

    $response = test()->postJson('/agent/v1/enroll', [
        'token' => $install->token,
        'csr_pem' => $csr,
        'facts' => fleet_facts($facts),
    ])->assertCreated();

    $agent = Agent::query()->findOrFail($response->json('agent_id'));
    $certificate = $agent->certificates()->firstOrFail();

    return ['agent' => $agent, 'certificate' => $certificate, 'fingerprint' => $certificate->fingerprint, 'response' => $response];
}

/**
 * Headers the edge forwards for an mTLS-authenticated agent.
 *
 * @return array<string, string>
 */
function fleet_mtls(string $fingerprint): array
{
    return ['X-Falak-Client-Cert-Fingerprint' => $fingerprint, 'Accept' => 'application/json'];
}

function fleet_ndjson(array $events): string
{
    return implode("\n", array_map(fn (array $event) => json_encode($event, JSON_UNESCAPED_SLASHES), $events))."\n";
}

/**
 * @return array<string, list<string>>
 */
function fleet_schema_errors(string $schema, mixed $document): array
{
    return app(ProtocolSchemas::class)->validate($schema, ProtocolSchemas::toJson($document));
}
