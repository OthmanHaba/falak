<?php

namespace Kiln\Processes\Application\Actions;

use Kiln\Identity\Contracts\AuditLog;
use Kiln\Processes\Application\ServerConverger;
use Kiln\Processes\Domain\Models\Daemon;
use Kiln\Processes\Domain\Models\Schedule;
use Kiln\Processes\Domain\Models\Worker;
use Kiln\Sites\Contracts\Data\SiteData;

/**
 * Remove a worker, daemon or scheduled job; the next apply stops it on every server.
 */
final class DeleteProcess
{
    public function __construct(
        private readonly ServerConverger $converger,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(SiteData $site, Worker|Daemon|Schedule $process): void
    {
        $kind = match (true) {
            $process instanceof Worker => 'worker',
            $process instanceof Daemon => 'daemon',
            default => 'schedule',
        };

        $process->delete();

        $this->audit->record("processes.{$kind}_deleted", 'site', $site->id, ["{$kind}_id" => $process->id], $site->organizationId);
        $this->converger->schedule(...$site->serverIds());
    }
}
