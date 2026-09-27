<?php

namespace Kiln\Deployments\Contracts\Data;

use DateTimeImmutable;

final readonly class DeploymentSummary
{
    /**
     * @param  string  $status  queued | building | deploying | succeeded | failed | cancelled
     * @param  ?int  $progress  0–100 while building / deploying (finished plan steps), null otherwise
     */
    public function __construct(
        public string $id,
        public string $siteId,
        public int $number,
        public string $status,
        public ?string $phase,
        public ?int $progress,
        public ?string $commit,
        public ?string $message,
        public ?string $error,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $finishedAt,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'building' || $this->status === 'deploying';
    }
}
