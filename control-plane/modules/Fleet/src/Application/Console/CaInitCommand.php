<?php

namespace Kiln\Fleet\Application\Console;

use Illuminate\Console\Command;
use Kiln\Fleet\Infrastructure\Pki\CertificateAuthorityService;

final class CaInitCommand extends Command
{
    protected $signature = 'fleet:ca:init';

    protected $description = 'Create the Kiln agent CA (if missing) and write ca.pem for the edge';

    public function handle(CertificateAuthorityService $ca): int
    {
        $authority = $ca->current();

        $this->components->info("CA {$authority->fingerprint} (valid until {$authority->not_after->toDateString()})");
        $this->components->twoColumnDetail('CA certificate', $ca->caFilePath());

        return self::SUCCESS;
    }
}
