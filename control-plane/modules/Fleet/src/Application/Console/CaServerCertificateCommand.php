<?php

namespace Kiln\Fleet\Application\Console;

use Illuminate\Console\Command;
use Kiln\Fleet\Infrastructure\Pki\CertificateAuthorityService;

/**
 * Agents pin the Kiln CA for the mTLS API, so the agent-facing listener needs a certificate issued by it.
 */
final class CaServerCertificateCommand extends Command
{
    protected $signature = 'fleet:ca:server-cert
        {hostnames* : DNS names / IPs of the agent API endpoint}
        {--days=397 : Validity in days}
        {--out= : Output directory (defaults to the CA path)}';

    protected $description = 'Issue a TLS server certificate for the agent-facing edge, signed by the Kiln CA';

    public function handle(CertificateAuthorityService $ca): int
    {
        /** @var list<string> $hostnames */
        $hostnames = array_values((array) $this->argument('hostnames'));
        $out = rtrim((string) ($this->option('out') ?: dirname($ca->caFilePath())), '/');

        ['certificate' => $certificate, 'private_key_pem' => $key] = $ca->issueServerCertificate($hostnames, (int) $this->option('days'));

        if (! is_dir($out)) {
            mkdir($out, 0755, true);
        }

        // Leaf first, then the CA; every block newline-terminated (Caddy/OpenSSL reject "-----END...----------BEGIN").
        file_put_contents("{$out}/agent-api.pem", CertificateAuthorityService::bundle($certificate->pem, $ca->caPem()));
        file_put_contents("{$out}/agent-api.key", $key);
        chmod("{$out}/agent-api.key", 0600);

        $this->components->info('Issued server certificate for '.implode(', ', $hostnames));
        $this->components->twoColumnDetail('Certificate (chain)', "{$out}/agent-api.pem");
        $this->components->twoColumnDetail('Private key', "{$out}/agent-api.key");
        $this->components->twoColumnDetail('Expires', $certificate->notAfter->format(DATE_ATOM));

        return self::SUCCESS;
    }
}
