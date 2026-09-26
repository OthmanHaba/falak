<?php

namespace Kiln\Deployments\Tests\Support;

use DateTimeInterface;
use Kiln\Telemetry\Contracts\Annotations;

final class FakeAnnotations implements Annotations
{
    /** @var list<array{deployment: string, site: string, status: string}> */
    public array $calls = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(Annotations::class, $fake);

        return $fake;
    }

    public function deployment(string $organizationId, string $deploymentId, string $siteId, string $status, ?string $text = null, ?DateTimeInterface $startedAt = null, ?DateTimeInterface $finishedAt = null, array $tags = []): ?int
    {
        $this->calls[] = ['deployment' => $deploymentId, 'site' => $siteId, 'status' => $status];

        return count($this->calls);
    }
}
