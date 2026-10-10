<?php

namespace Falak\SourceControl\Infrastructure\Webhooks;

use Falak\SourceControl\Contracts\Data\PullRequestData;

/**
 * A pull request delivery: opened (or reopened), updated, closed (merged or not), or a comment on it.
 */
final readonly class ParsedPullRequestEvent
{
    public const OPENED = 'opened';

    public const UPDATED = 'updated';

    public const CLOSED = 'closed';

    public const COMMENTED = 'commented';

    public function __construct(
        public string $kind,
        public string $repository,
        public int $number,
        /** Null for comments (their payloads don't carry the head reliably) */
        public ?PullRequestData $pullRequest = null,
        public bool $merged = false,
        public ?string $commentId = null,
        public ?string $commentAuthor = null,
        public string $commentBody = '',
    ) {}
}
