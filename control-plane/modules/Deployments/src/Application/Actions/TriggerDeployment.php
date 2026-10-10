<?php

namespace Falak\Deployments\Application\Actions;

use Closure;
use Falak\Deployments\Application\Orchestration\DeploymentLog;
use Falak\Deployments\Application\Orchestration\DeploymentQueue;
use Falak\Deployments\Contracts\Exceptions\DeploymentTriggerBusy;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Events\DeploymentUpdated;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Queue a deployment (manual, push, API/CLI, deploy hook, rollback) and start it when the site has
 * no deployment running. While the site's deployment waits for its servers to finish preparing, new
 * (non-rollback) triggers update that deployment instead of queueing another one.
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
     * @param  array<string, string>  $variables  FALAK_VAR_* for the deploy script
     * @param  ?int  $unlessNewerThan  a follow-up deploy (e.g. after a port change), given the newest deployment number the
     *                                 caller saw (0 for none): when the site has a queued or waiting deployment, or one
     *                                 numbered higher (created since), return the newest one untouched instead of queueing
     *                                 or coalescing. Checked under the site's trigger lock, so a push queued or started
     *                                 meanwhile is never followed by an older commit.
     * @param  ?string  $rollbackOf  a rollback started automatically by the watch window of that deployment
     * @param  ?Closure(): ?string  $guard  checked under the site's trigger lock before queueing: a reason refuses it
     *
     * @throws DeploymentTriggerBusy when another trigger of the site holds the lock for too long
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
        ?int $unlessNewerThan = null,
        ?string $rollbackOf = null,
        ?Closure $guard = null,
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

        $attributes = [
            'branch' => $branch,
            'commit' => $commit !== null ? strtolower($commit) : null,
            'commit_message' => $message !== null ? mb_substr($message, 0, 1000) : null,
            'commit_author' => $author !== null ? mb_substr($author, 0, 255) : null,
            'variables' => $variables === [] ? null : $variables,
            'requested_by' => $requestedBy,
        ];

        // Triggers of one site are serialized: a follow-up's "nothing newer" check and the coalescing into a waiting
        // deployment must not interleave with another trigger queueing a newer commit. Only database work runs locked.
        try {
            [$deployment, $outcome] = Cache::lock("deployments:trigger:{$site->id}", 30)->block((int) config('deployments.trigger_lock_wait', 15), function () use ($site, $trigger, $attributes, $releaseId, $unlessNewerThan, $rollbackOf, $guard) {
                if ($guard !== null && ($refused = $guard()) !== null) {
                    throw ValidationException::withMessages(['site' => $refused]);
                }

                if ($unlessNewerThan !== null) {
                    $newest = Deployment::query()->where('site_id', $site->id)->orderByDesc('number')->first();

                    if ($newest !== null && ($newest->number > $unlessNewerThan || in_array($newest->status, [DeploymentStatus::Queued, DeploymentStatus::Waiting], true))) {
                        return [$newest, 'existing'];
                    }
                }

                if ($trigger !== Trigger::Rollback && ($coalesced = $this->coalesce($site, $trigger, $attributes)) !== null) {
                    return [$coalesced, 'coalesced'];
                }

                return [$this->create($site, [
                    ...$attributes,
                    'trigger' => $trigger,
                    'status' => DeploymentStatus::Queued,
                    'target_release_id' => $trigger === Trigger::Rollback ? $releaseId : null,
                    'auto_rollback_of' => $trigger === Trigger::Rollback ? $rollbackOf : null,
                ]), 'created'];
            });
        } catch (LockTimeoutException) {
            throw DeploymentTriggerBusy::forSite();
        }

        if ($outcome === 'existing') {
            return $deployment;
        }

        if ($outcome === 'coalesced') {
            DeploymentUpdated::dispatch($deployment->id, $deployment->site_id, $deployment->status->value, $deployment->phase);
            // The servers may have become ready meanwhile.
            $this->queue->resume($site->id);

            return $deployment->refresh();
        }

        $this->audit->record('deployments.triggered', 'deployment', $deployment->id, array_filter([
            'site_id' => $site->id, 'trigger' => $trigger->value, 'commit' => $deployment->commit, 'release_id' => $releaseId, 'automatic_rollback_of' => $rollbackOf,
        ]), $site->organizationId, $requestedBy);

        $this->log->note($deployment->id, sprintf('Queued by %s%s.', $rollbackOf !== null ? 'the watch after a release went live (automatic rollback)' : $trigger->label(), $deployment->commit ? ' at '.substr($deployment->commit, 0, 7) : ''));

        $this->queue->startNext($site->id);

        return $deployment->refresh();
    }

    /**
     * Repeated triggers while the site's deployment waits for its servers fold into that deployment: the latest
     * trigger's branch/commit wins (like a push superseding an older one), and it keeps its place and number.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function coalesce(SiteData $site, Trigger $trigger, array $attributes): ?Deployment
    {
        $deployment = DB::transaction(function () use ($site, $trigger, $attributes) {
            $waiting = Deployment::query()->where('site_id', $site->id)->where('status', DeploymentStatus::Waiting)
                ->where('trigger', '!=', Trigger::Rollback)->lockForUpdate()->first();

            if ($waiting === null) {
                return null;
            }

            $waiting->forceFill(['trigger' => $trigger, ...$attributes])->save();

            return $waiting;
        });

        if ($deployment === null) {
            return null;
        }

        $this->audit->record('deployments.triggered', 'deployment', $deployment->id, array_filter([
            'site_id' => $site->id, 'trigger' => $trigger->value, 'commit' => $deployment->commit, 'coalesced' => true,
        ]), $site->organizationId, $attributes['requested_by'] ?? null);

        $this->log->note($deployment->id, sprintf('Updated by %s%s while waiting for the servers (the latest trigger wins).',
            $trigger->label(), $deployment->commit ? ' to '.substr($deployment->commit, 0, 7) : ''));

        return $deployment;
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
