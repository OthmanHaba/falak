<?php

namespace Falak\SourceControl\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified delivery: someone commented on a pull request (e.g. `/falak preview`). The author is the provider's
 * login; it proves nothing about Falak membership by itself.
 */
final class PullRequestCommented
{
    use Dispatchable;

    public function __construct(
        public string $organizationId,
        public string $connectionId,
        public string $provider,
        public string $repository,
        public int $number,
        public string $commentId,
        public ?string $author,
        public string $body,
    ) {}
}
