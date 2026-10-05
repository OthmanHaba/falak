<?php

namespace Falak\Fleet\Application\Console;

use Falak\Fleet\Infrastructure\Pki\CertificateAuthorityService;
use Illuminate\Console\Command;

final class CaInitCommand extends Command
{
    protected $signature = 'fleet:ca:init';

    protected $description = 'Create the Falak agent CA (if missing) and write ca.pem for the edge';

    public function handle(CertificateAuthorityService $ca): int
    {
        $authority = $ca->current();

        $this->components->info("CA {$authority->fingerprint} (valid until {$authority->not_after->toDateString()})");
        $this->components->twoColumnDetail('CA certificate', $ca->caFilePath());

        return self::SUCCESS;
    }
}
