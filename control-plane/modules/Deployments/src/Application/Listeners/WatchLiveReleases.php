<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Deployments\Application\Watch\ReleaseWatcher;
use Falak\Deployments\Domain\Enums\WatchTrigger;
use Falak\Deployments\Events\DeploymentStarted;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Insights\Contracts\IssueKind;
use Falak\Insights\Events\IssueOpened;
use Falak\Limits\Events\ServiceOomKilled;
use Falak\Limits\Events\ServiceRestartLoop;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Feeds the watch windows after releases go live ({@see ReleaseWatcher}): a successful deployment opens one, a newer
 * deployment ends the earlier ones, and OOM kills, restart loops and new Insights errors of a site are triggers.
 * Queued: opening a window reads the previous release's baseline from Loki, and a trigger may queue a rollback.
 */
final class WatchLiveReleases implements ShouldQueue
{
    public function __construct(private readonly ReleaseWatcher $watcher) {}

    public function succeeded(DeploymentSucceeded $event): void
    {
        $this->watcher->start($event->deploymentId);
    }

    public function started(DeploymentStarted $event): void
    {
        $this->watcher->stopForSite($event->siteId, 'Another deployment of the site started.', $event->deploymentId);
    }

    public function oomKilled(ServiceOomKilled $event): void
    {
        if ($event->siteId !== null) {
            $limit = $event->memoryLimitMb !== null ? " (memory limit {$event->memoryLimitMb} MB)" : ' (the server ran out of memory)';
            $this->watcher->signal($event->organizationId, strtolower($event->siteId), WatchTrigger::Crash, "{$event->label} was killed for running out of memory on {$event->serverName}{$limit}.");
        }
    }

    public function restartLoop(ServiceRestartLoop $event): void
    {
        if ($event->siteId !== null) {
            $this->watcher->signal($event->organizationId, strtolower($event->siteId), WatchTrigger::Crash, "{$event->label} restarted {$event->restarts} times in {$event->windowMinutes} minutes on {$event->serverName}.");
        }
    }

    public function issueOpened(IssueOpened $event): void
    {
        // Exceptions are the error-level issues (performance thresholds and missed heartbeats are not).
        if ($event->siteId !== null && $event->kind === IssueKind::Exception->value) {
            $this->watcher->signal($event->organizationId, strtolower($event->siteId), WatchTrigger::Issue, "New error in Insights: {$event->title}".($event->culprit ? " ({$event->culprit})" : '').'.');
        }
    }
}
