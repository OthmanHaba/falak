<?php

namespace Falak\Deployments\Application\Watch;

use Carbon\CarbonInterface;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Health\SiteHealthProbe;
use Falak\Deployments\Application\Orchestration\DeploymentLog;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Enums\WatchStatus;
use Falak\Deployments\Domain\Enums\WatchTrigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Deployments\Domain\Models\ReleaseWatch;
use Falak\Deployments\Domain\Models\SiteSettings;
use Falak\Deployments\Events\DeploymentUpdated;
use Falak\Deployments\Events\ReleaseWatchTriggered;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Telemetry\Contracts\AccessLogCounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Rollback after a release goes live (opt-in per site, Settings → Deploy). A successful deployment opens a watch
 * window (not the site's first deployment, nor a rollback); every 30 s its health check runs through the edge and
 * its 5xx rate is read from the edge access log, while OOM kills, restart loops and new Insights errors of the
 * site arrive as events. The first trigger that fires rolls the site back to the previous release through a
 * rollback deployment (Trigger::Rollback), or only alerts when the site is set to "alert only".
 *
 * Loop guard: never back to a release that was itself rolled back automatically, at most one automatic rollback per
 * site per hour, never while another deployment of the site is queued or running. A held-back rollback alerts.
 */
final class ReleaseWatcher
{
    /** The schedule's interval less some slack: a watch is never evaluated twice within it. */
    public const MIN_ROUND_SECONDS = 25;

    private const HEALTH_OFF = "The site's health check is off (Settings → Deploy), so this trigger is skipped.";

    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly SiteHealthProbe $probe,
        private readonly AccessLogCounts $counts,
        private readonly TriggerDeployment $trigger,
        private readonly DeploymentLog $log,
    ) {}

    /**
     * Open the watch window of a deployment that just succeeded (when its site watches releases).
     */
    public function start(string $deploymentId): ?ReleaseWatch
    {
        $deployment = Deployment::query()->find($deploymentId);

        // A first deployment has nothing to go back to; a rollback is itself the way back.
        if ($deployment === null || $deployment->status !== DeploymentStatus::Succeeded || $deployment->trigger === Trigger::Rollback
            || $deployment->previous_release_id === null || $deployment->release_id === null || (array) $deployment->setting('bootstrap', []) !== []) {
            return null;
        }

        $site = $this->sites->find($deployment->site_id);

        // Functions scale to zero: probing them would keep them up, and their gateway only switches to a booted release.
        if ($site === null || $site->runtime === SiteRuntime::Function) {
            return null;
        }

        $settings = SiteSettings::for($site)->watch();

        if (! $settings['enabled']) {
            return null;
        }

        // A re-delivered event never reopens a window (one that tripped or ended stays so).
        if (($existing = ReleaseWatch::query()->find($deployment->id)) !== null) {
            return $existing;
        }

        // Only the release the site runs now, with nothing queued behind it: a newer deployment replaces it anyway.
        if (Release::current($site->id)?->id !== $deployment->release_id
            || Deployment::query()->where('site_id', $site->id)->whereKeyNot($deployment->id)
                ->whereIn('status', [DeploymentStatus::Queued, ...DeploymentStatus::occupying()])->exists()) {
            return null;
        }

        // The health trigger uses the site's health check: without one (e.g. a login wall answering 302) it is skipped.
        $healthCheck = (bool) $deployment->setting('health.enabled', true);
        $settings['health'] = $settings['health'] && $healthCheck;

        $this->stopForSite($site->id, "Deployment #{$deployment->number} went live.", $deployment->id);

        $watch = ReleaseWatch::query()->create([
            'deployment_id' => $deployment->id,
            'organization_id' => $deployment->organization_id,
            'site_id' => $deployment->site_id,
            'release_id' => $deployment->release_id,
            'previous_release_id' => $deployment->previous_release_id,
            'status' => WatchStatus::Watching,
            'triggers' => [
                'health' => $settings['health'],
                'health_failures' => $settings['health_failures'],
                'crashes' => $settings['crashes'],
                'errors' => $settings['errors'],
                'issues' => $settings['issues'],
            ],
            'on_trigger' => $settings['on_trigger'],
            'migrations' => Migrations::forSite($site, Release::query()->find($deployment->release_id)),
            'baseline' => $settings['errors'] ? $this->baseline($deployment) : null,
            'checks' => $healthCheck ? null : ['health' => ['unavailable' => self::HEALTH_OFF]],
            'health_failures' => 0,
            'started_at' => now(),
            'ends_at' => now()->addMinutes($settings['minutes']),
        ]);

        $watched = array_keys(array_filter([
            "health check ({$settings['health_failures']} failures in a row)" => $settings['health'],
            'crashes and OOM kills' => $settings['crashes'],
            '5xx rate' => $settings['errors'],
            'new errors in Insights' => $settings['issues'],
        ]));

        $this->log->note($deployment->id, sprintf('Watching the release for %d minute(s): %s. On a trigger: %s.', $settings['minutes'], $watched !== [] ? implode(', ', $watched) : 'no triggers',
            $settings['on_trigger'] === ReleaseWatch::ALERT_ONLY ? 'alert only' : 'roll back to release '.strtoupper($deployment->previous_release_id)));
        $this->updated($deployment);

        return $watch;
    }

    /**
     * One round of a watch window: the health check and the 5xx rate; ends the window when its time is up.
     */
    public function evaluate(ReleaseWatch $watch): void
    {
        if ($watch->status !== WatchStatus::Watching) {
            return;
        }

        // Rounds are queued one per window and unique, but a slow round can still meet the next one: consecutive
        // health failures must be at least a probe interval apart.
        if ($watch->checked_at !== null && $watch->checked_at->greaterThan(now()->subSeconds(self::MIN_ROUND_SECONDS))) {
            return;
        }

        $deployment = Deployment::query()->find($watch->deployment_id);

        if ($deployment === null || $this->sites->find($watch->site_id) === null) {
            $this->finish($watch, WatchStatus::Stopped, 'The site no longer exists.');

            return;
        }

        $checks = $watch->checks ?? [];
        $failures = $watch->health_failures;
        $tripped = null;

        if (($watch->triggers['health'] ?? false) && ! $deployment->setting('health.enabled', true)) {
            $checks['health'] = ['unavailable' => self::HEALTH_OFF];
        } elseif ($watch->triggers['health'] ?? false) {
            $threshold = max(1, (int) ($watch->triggers['health_failures'] ?? 3));
            $health = (array) $deployment->setting('health', []);
            $failed = null;
            $message = null;

            foreach ($deployment->targets()->pluck('server_id') as $serverId) {
                [$ok, $probed] = $this->probe->probe($watch->site_id, (string) $serverId, $health);

                // A server deleted since, or without an address: nothing to check there, not a failure.
                if ($probed === SiteHealthProbe::NO_ADDRESS) {
                    continue;
                }

                $message = $probed;

                if (! $ok) {
                    $failed = $message;

                    break;
                }
            }

            $failures = $failed !== null ? $failures + 1 : 0;
            $checks['health'] = ['ok' => $failed === null, 'failures' => $failures, 'threshold' => $threshold, 'message' => $failed ?? $message];

            if ($failures >= $threshold) {
                $tripped = [WatchTrigger::Health, "The health check failed {$failures} times in a row: {$failed}"];
            }
        }

        if ($tripped === null && ($watch->triggers['errors'] ?? false)) {
            [$checks['errors'], $tripped] = $this->errorRate($watch);
        }

        $watch->forceFill(['checks' => $checks, 'health_failures' => $failures, 'checked_at' => now()])->save();

        if ($tripped !== null) {
            $this->trip($watch, $tripped[0], $tripped[1]);

            return;
        }

        if (now()->greaterThanOrEqualTo($watch->ends_at)) {
            $this->finish($watch, WatchStatus::Passed, 'No trigger fired; the watch window ended.');

            return;
        }

        $this->updated($deployment);
    }

    /**
     * A crash, OOM kill or new error of a site: trips the watch windows of the site that watch for it, when it happened
     * ($at) after the release went live.
     */
    public function signal(string $organizationId, string $siteId, WatchTrigger $trigger, string $reason, ?CarbonInterface $at = null): void
    {
        $watches = ReleaseWatch::query()->where('organization_id', strtolower($organizationId))->where('site_id', $siteId)->where('status', WatchStatus::Watching)->where('ends_at', '>', now())->get();

        foreach ($watches as $watch) {
            // The previous release's crashes (an event delivered late, a restart window that began before) don't count.
            if ($at !== null && $at->lessThan($watch->started_at)) {
                continue;
            }

            $enabled = match ($trigger) {
                WatchTrigger::Crash => $watch->triggers['crashes'] ?? false,
                WatchTrigger::Issue => $watch->triggers['issues'] ?? false,
                default => false,
            };

            if (! $enabled) {
                continue;
            }

            $checks = $watch->checks ?? [];
            $checks[$trigger->value] = ['events' => (int) ($checks[$trigger->value]['events'] ?? 0) + 1, 'message' => $reason];
            $watch->forceFill(['checks' => $checks])->save();

            $this->trip($watch, $trigger, $reason);
        }
    }

    /**
     * Another deployment of the site went live or started: the watch windows of earlier releases end.
     */
    public function stopForSite(string $siteId, string $why, ?string $exceptDeploymentId = null): void
    {
        ReleaseWatch::query()->where('site_id', $siteId)->where('status', WatchStatus::Watching)
            ->when($exceptDeploymentId !== null, fn ($q) => $q->whereKeyNot($exceptDeploymentId))
            ->get()->each(fn (ReleaseWatch $watch) => $this->finish($watch, WatchStatus::Stopped, $why));
    }

    /**
     * A trigger fired: roll back (unless the loop guard holds it back) or only alert.
     */
    public function trip(ReleaseWatch $watch, WatchTrigger $trigger, string $reason): void
    {
        $outcome = DB::transaction(function () use ($watch, $trigger, $reason) {
            $fresh = ReleaseWatch::query()->lockForUpdate()->find($watch->deployment_id);

            if ($fresh === null || $fresh->status !== WatchStatus::Watching) {
                return null;
            }

            $deployment = Deployment::query()->lockForUpdate()->findOrFail($fresh->deployment_id);
            $heldBack = $fresh->on_trigger === ReleaseWatch::ALERT_ONLY ? null : $this->heldBack($fresh);
            $rollback = $fresh->on_trigger === ReleaseWatch::ROLLBACK && $heldBack === null;

            $fresh->forceFill([
                'status' => $rollback ? WatchStatus::RolledBack : WatchStatus::Alerted,
                'trigger' => $trigger,
                'reason' => $reason,
                'finished_at' => now(),
            ])->save();

            $deployment->forceFill(['rolled_back_reason' => $reason] + ($rollback ? ['rolled_back' => true, 'rolled_back_at' => now()] : []))->save();

            if ($rollback) {
                Release::query()->whereKey($fresh->release_id)->update(['auto_rolled_back_at' => now(), 'updated_at' => now()]);
            }

            return [$fresh, $deployment, $rollback, $heldBack];
        });

        if ($outcome === null) {
            return;
        }

        /** @var ReleaseWatch $watch */
        /** @var Deployment $deployment */
        [$watch, $deployment, $rollback, $heldBack] = $outcome;
        $this->log->note($deployment->id, "Watch: {$trigger->label()} — {$reason}", stream: 'stderr');

        if ($rollback) {
            $heldBack = $this->rollBack($watch, $deployment);
        }

        if (! $rollback || $heldBack !== null) {
            $this->log->note($deployment->id, $heldBack !== null ? "Not rolled back: {$heldBack}" : 'Alert only: the release stays live.', stream: 'stderr');
            ReleaseWatchTriggered::dispatch($deployment->id, $deployment->organization_id, $deployment->site_id, $deployment->site_slug, $deployment->number,
                $trigger->value, $reason, $heldBack, $watch->migrations, $deployment->url());
        }

        $this->updated($deployment);
    }

    /**
     * Queue the rollback deployment to the previous release. Returns why it could not be queued (null on success).
     */
    private function rollBack(ReleaseWatch $watch, Deployment $deployment): ?string
    {
        $site = $this->sites->find($deployment->site_id);

        try {
            if ($site === null || $watch->previous_release_id === null) {
                throw new RuntimeException('the site no longer exists.');
            }

            // Checked again under the site's trigger lock: a deploy queued since the decision is never reverted.
            $rollback = ($this->trigger)($site, Trigger::Rollback, releaseId: $watch->previous_release_id, rollbackOf: $deployment->id,
                guard: fn () => $this->superseded($watch));
        } catch (Throwable $e) {
            $why = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
            Log::warning('deployments: automatic rollback not queued', ['deployment' => $deployment->id, 'error' => $why]);

            $watch->forceFill(['status' => WatchStatus::Alerted])->save();
            $deployment->forceFill(['rolled_back' => false, 'rolled_back_at' => null])->save();
            Release::query()->whereKey($watch->release_id)->update(['auto_rolled_back_at' => null, 'updated_at' => now()]);

            return $why;
        }

        $watch->forceFill(['rollback_deployment_id' => $rollback->id])->save();
        $this->log->note($deployment->id, sprintf('Rolling back to release %s: deployment #%d.%s', strtoupper((string) $watch->previous_release_id), $rollback->number,
            $watch->migrations ? ' Database migrations of this deployment are not reversed.' : ''), stream: 'stderr');

        return null;
    }

    /**
     * Why the watched release can't be rolled back any more: the site moved on (null: it is still the live one).
     */
    private function superseded(ReleaseWatch $watch): ?string
    {
        if (Release::current($watch->site_id)?->id !== $watch->release_id) {
            return 'the site no longer runs the watched release.';
        }

        if (Deployment::query()->where('site_id', $watch->site_id)->whereKeyNot($watch->deployment_id)
            ->whereIn('status', [DeploymentStatus::Queued, ...DeploymentStatus::occupying()])->exists()) {
            return 'another deployment of the site is in progress.';
        }

        return null;
    }

    /**
     * The loop guard: why an automatic rollback must not happen now (null: it may).
     */
    private function heldBack(ReleaseWatch $watch): ?string
    {
        $previous = $watch->previous_release_id !== null ? Release::query()->find($watch->previous_release_id) : null;

        if ($previous === null || ! $previous->canRollBackTo()) {
            return 'the previous release is no longer kept on the servers.';
        }

        if ($previous->auto_rolled_back_at !== null) {
            return 'the previous release was itself rolled back automatically.';
        }

        if (($superseded = $this->superseded($watch)) !== null) {
            return $superseded;
        }

        $cooldown = (int) config('deployments.watch.cooldown_minutes', 60);

        if (Deployment::query()->where('site_id', $watch->site_id)->whereNotNull('auto_rollback_of')->where('created_at', '>=', now()->subMinutes($cooldown))->exists()) {
            return "the site was already rolled back automatically in the last {$cooldown} minutes.";
        }

        return null;
    }

    /**
     * The release's 5xx rate since it went live against max(baseline × factor, the absolute rate).
     *
     * @return array{0: array<string, mixed>, 1: ?array{0: WatchTrigger, 1: string}}
     */
    private function errorRate(ReleaseWatch $watch): array
    {
        $minimum = max(1, (int) config('deployments.watch.min_requests', 20));
        $minimumErrors = max(1, (int) config('deployments.watch.min_errors', 5));
        $absolute = (float) config('deployments.watch.error_rate', 0.05);
        $factor = (float) config('deployments.watch.baseline_factor', 3);
        $baseline = $watch->baseline !== null ? (float) ($watch->baseline['rate'] ?? 0) : null;
        $threshold = max(($baseline ?? 0) * $factor, $absolute);

        try {
            $counts = $this->counts->forRelease($watch->organization_id, $watch->site_id, $watch->release_id, $watch->started_at, now());
        } catch (Throwable $e) {
            return [['unavailable' => 'The edge access log could not be read: '.$e->getMessage(), 'threshold' => $threshold, 'min_requests' => $minimum], null];
        }

        $check = [...$counts->toArray(), 'threshold' => round($threshold, 4), 'min_requests' => $minimum, 'min_errors' => $minimumErrors];

        // A rate over a handful of errors is noise: it needs enough requests and enough 5xx answers.
        if ($counts->total < $minimum || $counts->errors < $minimumErrors || $counts->errorRate() <= $threshold) {
            return [$check, null];
        }

        $against = $baseline !== null && $baseline * $factor > $absolute
            ? sprintf('%g × the previous release\'s %.1f%%', $factor, $baseline * 100)
            : sprintf('%.0f%% absolute%s', $absolute * 100, $baseline === null ? ', no baseline from the previous release' : '');

        return [$check, [WatchTrigger::Errors, sprintf('The 5xx rate was %.1f%% (%d of %d requests), above %.1f%% (%s).',
            $counts->errorRate() * 100, $counts->errors, $counts->total, $threshold * 100, $against)]];
    }

    /**
     * The previous release's 5xx rate over its last hour live (null without enough requests or data).
     *
     * @return ?array{total: int, errors: int, rate: float}
     */
    private function baseline(Deployment $deployment): ?array
    {
        try {
            $counts = $this->counts->forRelease($deployment->organization_id, $deployment->site_id, (string) $deployment->previous_release_id,
                now()->subMinutes((int) config('deployments.watch.baseline_minutes', 60)), now());
        } catch (Throwable) {
            return null;
        }

        return $counts->total >= max(1, (int) config('deployments.watch.min_requests', 20)) ? $counts->toArray() : null;
    }

    private function finish(ReleaseWatch $watch, WatchStatus $status, string $note): void
    {
        $finished = ReleaseWatch::query()->whereKey($watch->deployment_id)->where('status', WatchStatus::Watching)
            ->update(['status' => $status, 'finished_at' => now(), 'updated_at' => now()]);

        if ($finished === 0) {
            return;
        }

        $watch->refresh();
        $this->log->note($watch->deployment_id, "Watch: {$note}");

        if (($deployment = Deployment::query()->find($watch->deployment_id)) !== null) {
            $this->updated($deployment);
        }
    }

    private function updated(Deployment $deployment): void
    {
        DeploymentUpdated::dispatch($deployment->id, $deployment->site_id, $deployment->status->value, $deployment->phase);
    }
}
