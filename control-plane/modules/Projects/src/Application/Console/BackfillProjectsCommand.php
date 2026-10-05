<?php

namespace Falak\Projects\Application\Console;

use Illuminate\Console\Command;
use Falak\Projects\Application\Actions\BackfillProjects;

final class BackfillProjectsCommand extends Command
{
    protected $signature = 'projects:backfill {--organization= : Only this organization id}';

    protected $description = 'Create missing default projects and place unplaced sites and databases into them';

    public function handle(BackfillProjects $backfill): int
    {
        $organization = $this->option('organization');
        $counts = $backfill(is_string($organization) && $organization !== '' ? strtolower($organization) : null);

        $this->components->info(sprintf(
            'Checked %d organization(s): %d default project(s) created, %d site(s) and %d database(s) placed.',
            $counts['organizations'], $counts['projects'], $counts['sites'], $counts['databases'],
        ));

        return self::SUCCESS;
    }
}
