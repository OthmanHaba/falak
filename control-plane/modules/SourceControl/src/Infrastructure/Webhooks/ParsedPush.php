<?php

namespace Falak\SourceControl\Infrastructure\Webhooks;

use Falak\SourceControl\Contracts\Data\CommitData;

final readonly class ParsedPush
{
    public function __construct(
        public string $branch,
        public CommitData $commit,
        public ?string $pusher,
        public ?string $beforeSha,
    ) {}
}
