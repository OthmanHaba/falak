<?php

namespace Falak\Builds\Application\Console;

use Illuminate\Console\Command;
use Falak\Builds\Application\RegistryPruner;

final class RegistryPruneCommand extends Command
{
    protected $signature = 'falak:registry-prune {--dry-run : list what would be deleted, delete nothing}';

    protected $description = 'Delete built-in registry images no build or release needs any more';

    public function handle(RegistryPruner $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $pruner->prune($dryRun);

        foreach ($result['deleted'] as $manifest) {
            $this->line(($dryRun ? 'would delete ' : 'deleted ').$manifest);
        }

        if ($result['skipped'] !== null) {
            $this->warn($result['skipped']);

            return $result['deleted'] === [] ? self::FAILURE : self::SUCCESS;
        }

        $this->info(sprintf('%d image(s) %s, %d kept. Run `falak-ctl registry gc` to reclaim the space.', count($result['deleted']), $dryRun ? 'would be deleted' : 'deleted', $result['kept']));

        return self::SUCCESS;
    }
}
