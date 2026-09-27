<?php

namespace Kiln\Deployments\Infrastructure;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Kiln\Deployments\Contracts\Data\DeploymentSummary;
use Kiln\Deployments\Contracts\DeploymentDirectory;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\StepStatus;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\DeploymentStep;

final class EloquentDeploymentDirectory implements DeploymentDirectory
{
    public function currentForSites(array $siteIds): array
    {
        $siteIds = array_values(array_unique($siteIds));

        if ($siteIds === []) {
            return [];
        }

        $active = Deployment::query()
            ->whereIn('site_id', $siteIds)
            ->whereIn('status', DeploymentStatus::active())
            ->orderByDesc('number')
            ->get()
            ->unique('site_id')
            ->keyBy('site_id');

        $newest = Deployment::query()
            ->selectRaw('site_id as latest_site_id, max(number) as latest_number')
            ->whereIn('site_id', array_values(array_diff($siteIds, $active->keys()->all())))
            ->groupBy('site_id');

        $latest = Deployment::query()
            ->joinSub($newest, 'latest', fn ($join) => $join
                ->on('deployments_deployments.site_id', '=', 'latest.latest_site_id')
                ->on('deployments_deployments.number', '=', 'latest.latest_number'))
            ->select('deployments_deployments.*')
            ->get()
            ->keyBy('site_id');

        /** @var array<string, Deployment> $deployments */
        $deployments = $active->union($latest)->all();

        return $this->summaries($deployments);
    }

    public function recentForSites(array $siteIds, int $limit = 20): array
    {
        $siteIds = array_values(array_unique($siteIds));

        if ($siteIds === []) {
            return [];
        }

        $deployments = Deployment::query()->whereIn('site_id', $siteIds)->latest()->orderByDesc('id')->limit(max(1, $limit))->get()->all();

        return array_values($this->summaries($deployments));
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, Deployment>  $deployments
     * @return array<TKey, DeploymentSummary>
     */
    private function summaries(array $deployments): array
    {
        $progress = $this->progress(array_values(array_map(fn (Deployment $d) => $d->id, array_filter($deployments, fn (Deployment $d) => $d->status->isActive()))));

        return array_map(fn (Deployment $deployment) => new DeploymentSummary(
            id: $deployment->id,
            siteId: $deployment->site_id,
            number: $deployment->number,
            status: $deployment->status->value,
            phase: $deployment->phase,
            progress: $deployment->status->isActive() ? ($progress[$deployment->id] ?? 0) : null,
            commit: $deployment->commit,
            message: $deployment->commit_message !== null ? (string) strtok($deployment->commit_message, "\n") : null,
            error: $deployment->error,
            createdAt: self::date($deployment->created_at) ?? new DateTimeImmutable,
            startedAt: self::date($deployment->started_at),
            finishedAt: self::date($deployment->finished_at),
        ), $deployments);
    }

    /**
     * Share of forward (non-rollback) plan steps that finished, per deployment.
     *
     * @param  list<string>  $deploymentIds
     * @return array<string, int>
     */
    private function progress(array $deploymentIds): array
    {
        if ($deploymentIds === []) {
            return [];
        }

        $finished = [StepStatus::Succeeded->value, StepStatus::Failed->value, StepStatus::Skipped->value];

        return DeploymentStep::query()
            ->whereIn('deployment_id', $deploymentIds)
            ->where('rollback', false)
            ->get(['deployment_id', 'status'])
            ->groupBy('deployment_id')
            ->map(fn ($steps) => (int) floor(100 * $steps->filter(fn (DeploymentStep $s) => in_array($s->status->value, $finished, true))->count() / max(1, $steps->count())))
            ->all();
    }

    private static function date(?Carbon $date): ?DateTimeImmutable
    {
        return $date?->toDateTimeImmutable();
    }
}
