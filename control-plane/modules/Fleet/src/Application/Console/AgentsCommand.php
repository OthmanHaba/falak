<?php

namespace Falak\Fleet\Application\Console;

use Falak\Fleet\Application\ShippedAgent;
use Falak\Fleet\Contracts\AgentUpgrades;
use Illuminate\Console\Command;

/**
 * `php artisan falak:agents --outdated [--count]` — agents running an older build than this control plane ships
 * (falak-ctl update prints a hint with it).
 */
final class AgentsCommand extends Command
{
    protected $signature = 'falak:agents {--outdated : Only count agents that run an older build than the one shipped} {--count : Print only the number}';

    protected $description = 'Report the falak-agent build shipped by this control plane and how many agents are outdated';

    public function handle(AgentUpgrades $upgrades, ShippedAgent $shipped): int
    {
        $outdated = $upgrades->outdatedCount();

        if ($this->option('count')) {
            $this->line((string) $outdated);

            return self::SUCCESS;
        }

        foreach (['amd64', 'arm64'] as $arch) {
            $build = $shipped->for($arch);
            $this->line("falak-agent linux-{$arch}: ".($build ? "{$build['version']} (sha256 {$build['sha256']})" : 'not published'));
        }

        $this->line("{$outdated} agent(s) run an older build.".($outdated > 0 ? ' Update them under Servers → Update all agents (or POST /api/v1/servers/{server}/agent/upgrade).' : ''));

        return self::SUCCESS;
    }
}
