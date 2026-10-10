<?php

namespace Falak\SourceControl\Events;

use Falak\SourceControl\Contracts\Data\PullRequestData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified delivery: an open pull request changed (GitHub `synchronize`: new commits; GitLab and Bitbucket also
 * announce other edits, so compare the head sha).
 */
final class PullRequestUpdated
{
    use Dispatchable;

    public function __construct(
        public string $organizationId,
        public string $connectionId,
        public string $provider,
        public PullRequestData $pullRequest,
    ) {}
}
