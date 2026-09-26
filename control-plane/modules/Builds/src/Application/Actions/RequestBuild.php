<?php

namespace Kiln\Builds\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kiln\Builds\Application\BuildConfiguration;
use Kiln\Builds\Application\BuildProgress;
use Kiln\Builds\Contracts\BuildStatus;
use Kiln\Builds\Contracts\Data\BuildRequest;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Sites\Contracts\SiteDirectory;

/**
 * Queue a build for a site, or reuse an identical earlier build whose artifact is still retained.
 */
final class RequestBuild
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly BuildConfiguration $configuration,
        private readonly BuildProgress $progress,
    ) {}

    public function __invoke(BuildRequest $request): Build
    {
        $site = $this->sites->find($request->siteId) ?? throw new InvalidArgumentException('The site does not exist.');
        $mode = BuildConfiguration::mode($site) ?? throw new InvalidArgumentException('On-server builds do not run on builders.');

        if ($site->repository === null || $site->sourceConnectionId === null) {
            throw new InvalidArgumentException('The site has no repository to build.');
        }

        $commit = $request->commit !== null ? strtolower($request->commit) : null;
        $cacheKey = $this->configuration->cacheKey($site, $mode, $commit);
        $branch = $request->branch ?? $site->branch;

        if ($commit !== null && $request->allowReuse) {
            $previous = Build::query()
                ->where('site_id', $site->id)
                ->where('cache_key', $cacheKey)
                ->where('status', BuildStatus::Succeeded)
                ->whereNull('artifact_pruned_at')
                ->latest()
                ->first();

            if ($previous !== null && $previous->hasArtifact()) {
                $build = Build::query()->create([
                    ...$previous->only(['organization_id', 'site_id', 'site_slug', 'mode', 'repository', 'cache_key', 'resolved_commit', 'artifact_key',
                        'artifact_sha256', 'artifact_size', 'artifact_format', 'image_ref', 'image_digest', 'manifest', 'builder_id']),
                    'id' => strtolower((string) Str::ulid()),
                    'deployment_id' => $request->deploymentId,
                    'status' => BuildStatus::Succeeded,
                    'branch' => $branch,
                    'commit' => $commit,
                    'reused_build_id' => $previous->reused_build_id ?? $previous->id,
                    'timeout_s' => $previous->timeout_s,
                    'exit_code' => 0,
                    'duration_ms' => 0,
                    'requested_by' => $request->requestedBy,
                    'started_at' => now(),
                    'finished_at' => now(),
                ]);

                $this->progress->log($build, ["Reusing the artifact of build {$build->reused_build_id} (same commit and build configuration).\n"]);

                return $build;
            }
        }

        return DB::transaction(fn () => Build::query()->create([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $site->organizationId,
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'deployment_id' => $request->deploymentId,
            'mode' => $mode,
            'status' => BuildStatus::Queued,
            'repository' => $site->repository,
            'branch' => $branch,
            'commit' => $commit,
            'cache_key' => $cacheKey,
            'timeout_s' => max(60, min(3600 * 3, (int) config('builds.timeout', 1800))),
            'requested_by' => $request->requestedBy,
        ]));
    }
}
