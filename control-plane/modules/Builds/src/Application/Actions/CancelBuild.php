<?php

namespace Kiln\Builds\Application\Actions;

use Kiln\Builds\Application\BuildProgress;
use Kiln\Builds\Domain\Models\Build;
use Kiln\Identity\Contracts\AuditLog;

/**
 * Cancel a build. Running builds are aborted by the builder on its next event delivery (HTTP 410).
 */
final class CancelBuild
{
    public function __construct(
        private readonly BuildProgress $progress,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(Build $build, string $reason = 'Cancelled by a user.', ?string $actorId = null): bool
    {
        if (! $this->progress->cancel($build, $reason)) {
            return false;
        }

        $this->audit->record('builds.cancelled', 'build', $build->id, ['site_id' => $build->site_id], $build->organization_id, $actorId);

        return true;
    }
}
