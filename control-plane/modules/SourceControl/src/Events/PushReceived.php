<?php

namespace Falak\SourceControl\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Falak\SourceControl\Contracts\Data\CommitData;

/**
 * A verified push webhook for a branch (tag pushes and branch deletions are not announced).
 */
final class PushReceived
{
    use Dispatchable;

    public function __construct(
        public string $organizationId,
        public string $connectionId,
        public string $provider,
        public string $repository,
        public string $branch,
        public CommitData $commit,
        public ?string $pusher = null,
        public ?string $beforeSha = null,
    ) {}
}
