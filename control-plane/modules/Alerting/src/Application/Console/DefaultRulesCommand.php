<?php

namespace Falak\Alerting\Application\Console;

use Falak\Alerting\Application\DefaultRulePack;
use Falak\Identity\Contracts\OrganizationDirectory;
use Illuminate\Console\Command;

/**
 * `php artisan alerting:default-rules` — applies the default rule pack to every organization: areas registered since
 * (a module's new alert group) are added; rules and patterns the organization deleted stay deleted. Runs daily.
 */
final class DefaultRulesCommand extends Command
{
    protected $signature = 'alerting:default-rules';

    protected $description = 'Apply the default alert rule pack to every organization (new areas only; deleted rules stay deleted)';

    public function handle(DefaultRulePack $pack, OrganizationDirectory $organizations): int
    {
        $changed = 0;

        foreach ($organizations->all() as $organization) {
            $changed += $pack->apply($organization->id);
        }

        $this->line("{$changed} rule(s) created or extended.");

        return self::SUCCESS;
    }
}
