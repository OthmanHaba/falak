<?php

namespace Kiln\Builds\Application\Actions;

use Kiln\Builds\Application\BuildProgress;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Builds\Domain\Models\Builder;

/**
 * A kiln-builder polls only when it has nothing to do, with a run id that is new for every process. Builds its name
 * claimed under another run id belong to a process that is gone (restarted mid-build, e.g. `kiln-ctl update`
 * recreating the builder container): fail them now instead of when their timeout runs out.
 */
final class ReapOrphanedBuilds
{
    public function __construct(private readonly BuildProgress $progress) {}

    public function __invoke(Builder $builder, string $runId, ?string $name): int
    {
        $orphans = Build::query()
            ->where('builder_id', $builder->id)
            ->whereIn('status', [BuildStatus::Assigned, BuildStatus::Running])
            ->whereNotNull('builder_run_id')
            ->where('builder_run_id', '!=', $runId)
            ->when($name !== null, fn ($q) => $q->where('builder_name', $name), fn ($q) => $q->whereNull('builder_name'))
            ->get();

        foreach ($orphans as $build) {
            $label = $name ?? $builder->name;
            $this->progress->log($build, ["Builder {$label} restarted during the build.\n"], 'stderr');
            $this->progress->fail($build, "Builder {$label} restarted during the build.");
        }

        return $orphans->count();
    }
}
