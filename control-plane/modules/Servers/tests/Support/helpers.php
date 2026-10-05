<?php

use Falak\Fleet\Domain\Models\Command;
use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Falak\Servers\Domain\Models\Server;

require_once __DIR__.'/../../../Fleet/tests/Support/helpers.php';

/**
 * Simulate the agent: enroll for the server (using the server's install token) and return mTLS headers.
 *
 * @return array{headers: array<string, string>, agent_id: string}
 */
function servers_enroll_agent(Server $server, array $facts = []): array
{
    preg_match('#/install/([A-Za-z0-9]+)#', (string) $server->install_command, $m);
    [$csr] = fleet_csr();

    $response = test()->postJson('/agent/v1/enroll', ['token' => $m[1], 'csr_pem' => $csr, 'facts' => fleet_facts($facts)])->assertCreated();
    $fingerprint = CertificateAuthorityService::fingerprint($response->json('cert_pem'));

    return ['headers' => fleet_mtls($fingerprint), 'agent_id' => $response->json('agent_id')];
}

/**
 * Poll as the agent and return the delivered envelopes.
 *
 * @return list<array<string, mixed>>
 */
function servers_poll(array $headers): array
{
    return test()->getJson('/agent/v1/commands?wait=0', $headers)->assertOk()->json('commands');
}

function servers_finish(array $headers, string $commandId, int $exitCode = 0, ?string $error = null, ?array $result = null): void
{
    $events = [
        ['command_id' => $commandId, 'seq' => 0, 'kind' => 'started', 'at' => now()->toIso8601ZuluString()],
        ['command_id' => $commandId, 'seq' => 1, 'kind' => 'output', 'stream' => 'stdout', 'data' => "ok\n", 'at' => now()->toIso8601ZuluString()],
        array_filter(['command_id' => $commandId, 'seq' => 2, 'kind' => 'finished', 'exit_code' => $exitCode, 'error' => $error, 'result' => $result, 'at' => now()->toIso8601ZuluString()], fn ($v) => $v !== null),
    ];

    test()->call('POST', "/agent/v1/commands/{$commandId}/events", [], [], [], test()->transformHeadersToServerVars($headers), fleet_ndjson($events))->assertNoContent();
}

function servers_last_command(string $type): Command
{
    return Command::query()->where('type', $type)->latest('queued_at')->orderByDesc('id')->firstOrFail();
}
