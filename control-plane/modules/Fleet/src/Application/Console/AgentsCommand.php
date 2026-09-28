<?php

namespace Kiln\Fleet\Application\Console;

use Illuminate\Console\Command;
use Kiln\Fleet\Application\ShippedAgent;
use Kiln\Fleet\Contracts\AgentUpgrades;

/**
 * `php artisan kiln:agents --outdated [--count]` — agents running an older build than this control plane ships
 * (kiln-ctl update prints a hint with it).
 */
final class AgentsCommand extends Command
{
    protected $signature = 'kiln:agents {--outdated : Only count agents that run an older build than the one shipped} {--count : Print only the number}';

    protected $description = 'Report the kiln-agent build shipped by this control plane and how many agents are outdated';

    public function handle(AgentUpgrades $upgrades, ShippedAgent $shipped): int
    {
        $outdated = $upgrades->outdatedCount();

        if ($this->option('count')) {
            $this->line((string) $outdated);

            return self::SUCCESS;
        }

        foreach (['amd64', 'arm64'] as $arch) {
            $build = $shipped->for($arch);
            $this->line("kiln-agent linux-{$arch}: ".($build ? "{$build['version']} (sha256 {$build['sha256']})" : 'not published'));
        }

        $this->line("{$outdated} agent(s) run an older build.".($outdated > 0 ? ' Upgrade them under Servers → Upgrade all agents (or POST /api/v1/servers/{server}/agent/upgrade).' : ''));

        return self::SUCCESS;
    }
}
