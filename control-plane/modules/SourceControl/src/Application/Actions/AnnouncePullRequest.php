<?php

namespace Falak\SourceControl\Application\Actions;

use Falak\SourceControl\Contracts\Data\PullRequestData;
use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Events\PullRequestClosed;
use Falak\SourceControl\Events\PullRequestCommented;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Falak\SourceControl\Infrastructure\Webhooks\ParsedPullRequestEvent;
use Illuminate\Support\Str;

/**
 * Announce a verified pull request delivery for a repository Falak knows (Previews acts on the events). The repository
 * is the one Falak stored (its casing), not the payload's.
 */
final class AnnouncePullRequest
{
    public function __invoke(Connection $connection, string $repository, ?ParsedPullRequestEvent $event): bool
    {
        if ($event === null) {
            return false;
        }

        $provider = $connection->provider->value;

        if ($event->kind === ParsedPullRequestEvent::COMMENTED) {
            PullRequestCommented::dispatch($connection->organization_id, $connection->id, $provider, $repository, $event->number,
                (string) $event->commentId, $event->commentAuthor, Str::limit($event->commentBody, 4000, ''), $event->commentAuthorId, $event->commentedAt);

            return true;
        }

        $pr = $event->pullRequest;

        if ($pr === null) {
            return false;
        }

        $pr = new PullRequestData(
            repository: $repository,
            number: $pr->number,
            title: Str::limit($pr->title, 250, ''),
            url: $pr->url !== null ? Str::limit($pr->url, 1000, '') : null,
            headBranch: Str::limit($pr->headBranch, 250, ''),
            headSha: Str::limit($pr->headSha, 64, ''),
            baseBranch: Str::limit($pr->baseBranch, 250, ''),
            author: $pr->author !== null ? Str::limit($pr->author, 250, '') : null,
            isFork: $pr->isFork,
            sourceRepository: $pr->sourceRepository,
        );

        match ($event->kind) {
            ParsedPullRequestEvent::OPENED => PullRequestOpened::dispatch($connection->organization_id, $connection->id, $provider, $pr),
            ParsedPullRequestEvent::UPDATED => PullRequestUpdated::dispatch($connection->organization_id, $connection->id, $provider, $pr),
            default => PullRequestClosed::dispatch($connection->organization_id, $connection->id, $provider, $pr, $event->merged),
        };

        return true;
    }
}
