<?php

namespace Falak\SourceControl\Events;

use Falak\SourceControl\Contracts\Data\PullRequestData;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A verified delivery: a pull request was opened or reopened (GitLab: a merge request).
 */
final class PullRequestOpened
{
    use Dispatchable;

    public function __construct(
        public string $organizationId,
        public string $connectionId,
        public string $provider,
        public PullRequestData $pullRequest,
    ) {}
}
