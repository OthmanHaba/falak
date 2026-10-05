<?php

namespace Falak\Builds\Contracts\Data;

use DateTimeImmutable;
use Falak\Builds\Contracts\BuildStatus;

final readonly class BuildData
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $siteId,
        public string $mode,
        public BuildStatus $status,
        public ?string $branch,
        public ?string $commit,
        public ?string $deploymentId,
        public bool $reused,
        public ?string $error,
        public ?string $builderName,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
        public ?string $imageRef = null,
    ) {}

    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    public function isSuccessful(): bool
    {
        return $this->status === BuildStatus::Succeeded;
    }
}
