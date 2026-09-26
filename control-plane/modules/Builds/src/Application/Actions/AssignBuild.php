<?php

namespace Kiln\Builds\Application\Actions;

use Illuminate\Support\Facades\DB;
use Kiln\Builds\Application\BuildProgress;
use Kiln\Builds\Application\JobPayload;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Builds\Domain\Models\Builder;
use Kiln\Builds\Events\BuildUpdated;
use Throwable;

/**
 * Hand the oldest eligible queued build to a polling builder. The claim is a conditional UPDATE, so
 * two builders polling at once never get the same build.
 */
final class AssignBuild
{
    public function __construct(
        private readonly JobPayload $payloads,
        private readonly BuildProgress $progress,
    ) {}

    /**
     * @return ?array{build: Build, job: array<string, mixed>}
     */
    public function __invoke(Builder $builder): ?array
    {
        if (! $builder->enabled) {
            return null;
        }

        $candidates = Build::query()
            ->where('status', BuildStatus::Queued)
            ->whereIn('mode', $builder->modes)
            ->when($builder->organization_id !== null, fn ($q) => $q->where('organization_id', $builder->organization_id))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($candidates as $candidate) {
            $key = $candidate->mode === 'native' ? JobPayload::artifactKey($candidate) : null;

            $claimed = Build::query()->whereKey($candidate->id)->where('status', BuildStatus::Queued)->update([
                'status' => BuildStatus::Assigned,
                'builder_id' => $builder->id,
                'assigned_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'artifact_key' => $key,
                'updated_at' => now(),
            ]);

            if ($claimed !== 1) {
                continue;
            }

            $build = $candidate->refresh();

            try {
                $job = $this->payloads->for($build);
            } catch (Throwable $e) {
                $this->progress->fail($build, 'Could not prepare the build: '.$e->getMessage());

                continue;
            }

            $this->progress->log($build, ["Assigned to builder {$builder->name} (attempt {$build->attempts}).\n"]);
            BuildUpdated::dispatch($build->id, $build->status->value, $build->progress, null);

            return ['build' => $build, 'job' => $job];
        }

        return null;
    }
}
