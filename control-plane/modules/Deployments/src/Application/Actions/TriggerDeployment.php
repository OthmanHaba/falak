<?php

namespace Kiln\Deployments\Application\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Kiln\Deployments\Application\Orchestration\DeploymentLog;
use Kiln\Deployments\Application\Orchestration\DeploymentQueue;
use Kiln\Deployments\Domain\Enums\DeploymentStatus;
use Kiln\Deployments\Domain\Enums\Trigger;
use Kiln\Deployments\Domain\Models\Deployment;
use Kiln\Deployments\Domain\Models\Release;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\SourceControl\Contracts\SourceControlGateway;
use Throwable;

/**
 * Queue a deployment (manual, push, API/CLI, deploy hook, rollback) and start it when the site has
 * no deployment running.
 */
final class TriggerDeployment
{
    public function __construct(
        private readonly SourceControlGateway $sourceControl,
        private readonly DeploymentQueue $queue,
        private readonly DeploymentLog $log,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, string>  $variables  KILN_VAR_* for the deploy script
     */
    public function __invoke(
        SiteData $site,
        Trigger $trigger,
        ?string $branch = null,
        ?string $commit = null,
        ?string $message = null,
        ?string $author = null,
        array $variables = [],
        ?string $requestedBy = null,
        ?string $releaseId = null,
    ): Deployment {
        $branch = $branch !== null && $branch !== '' ? $branch : $site->branch;

        if ($trigger === Trigger::Rollback) {
            $release = $releaseId !== null
                ? Release::query()->where('site_id', $site->id)->find(strtolower($releaseId))
                : Release::query()->where('site_id', $site->id)->where('status', 'inactive')->orderByDesc('activated_at')->orderByDesc('created_at')->orderByDesc('id')->first();

            if (! $release || ! $release->canRollBackTo()) {
                throw ValidationException::withMessages(['release_id' => $releaseId ? 'This release cannot be rolled back to (it is current, failed or pruned).' : 'There is no earlier release to roll back to.']);
            }

            [$branch, $commit, $message, $author] = [$release->branch, $release->commit, $release->commit_message, $release->commit_author];
            $releaseId = $release->id;
        } elseif (! $site->hasDeploySource()) {
            throw ValidationException::withMessages(['site' => $site->compose !== null ? 'Add a compose file (Settings → Compose) or connect a repository before deploying.' : 'Connect a repository before deploying.']);
        } elseif ($commit === null && $site->sourceConnectionId !== null && $site->repository !== null && $branch !== null) {
            try {
                $head = $this->sourceControl->latestCommit($site->sourceConnectionId, $site->repository, $branch);
            } catch (Throwable) {
                $head = null;
            }

            if ($head !== null) {
                [$commit, $message, $author] = [$head->sha, $message ?? $head->message, $author ?? $head->authorName];
            }
        }

        if ($commit !== null && preg_match('/^[0-9a-fA-F]{7,64}$/', $commit) !== 1) {
            throw ValidationException::withMessages(['commit' => 'The commit must be a hexadecimal SHA.']);
        }

        $deployment = $this->create($site, [
            'trigger' => $trigger,
            'status' => DeploymentStatus::Queued,
            'branch' => $branch,
            'commit' => $commit !== null ? strtolower($commit) : null,
            'commit_message' => $message !== null ? mb_substr($message, 0, 1000) : null,
            'commit_author' => $author !== null ? mb_substr($author, 0, 255) : null,
            'target_release_id' => $trigger === Trigger::Rollback ? $releaseId : null,
            'variables' => $variables === [] ? null : $variables,
            'requested_by' => $requestedBy,
        ]);

        $this->audit->record('deployments.triggered', 'deployment', $deployment->id, array_filter([
            'site_id' => $site->id, 'trigger' => $trigger->value, 'commit' => $deployment->commit, 'release_id' => $releaseId,
        ]), $site->organizationId, $requestedBy);

        $this->log->note($deployment->id, sprintf('Queued by %s%s.', $trigger->label(), $deployment->commit ? ' at '.substr($deployment->commit, 0, 7) : ''));

        $this->queue->startNext($site->id);

        return $deployment->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function create(SiteData $site, array $attributes): Deployment
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => Deployment::query()->create([
                    ...$attributes,
                    'organization_id' => $site->organizationId,
                    'site_id' => $site->id,
                    'site_slug' => $site->slug,
                    'number' => (int) Deployment::query()->where('site_id', $site->id)->max('number') + 1,
                ]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }
}
