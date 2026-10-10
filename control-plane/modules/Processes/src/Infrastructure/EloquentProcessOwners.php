<?php

namespace Falak\Processes\Infrastructure;

use Falak\Limits\Contracts\ResourceLimits;
use Falak\Processes\Application\StatusPoller;
use Falak\Processes\Contracts\Data\ProcessOwner;
use Falak\Processes\Contracts\ProcessOwners;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Domain\Models\Worker;
use Falak\Sites\Contracts\SiteDirectory;

final class EloquentProcessOwners implements ProcessOwners
{
    public function __construct(
        private readonly SiteDirectory $sites,
    ) {}

    public function program(string $serverId, string $program): ?ProcessOwner
    {
        $state = ServerState::query()->find($serverId);
        $meta = $state?->programs[$program] ?? null;

        if (! is_array($meta) || ! is_string($meta['site_id'] ?? null)) {
            return null;
        }

        $kind = (string) ($meta['kind'] ?? '');

        if (in_array($kind, ['worker', 'daemon'], true) && is_string($meta['ref_id'] ?? null)) {
            return $this->process($kind, $meta['ref_id'], $serverId);
        }

        $site = $this->sites->find($meta['site_id']);

        if ($site === null) {
            return null;
        }

        $limits = $site->limits;

        return new ProcessOwner($site->organizationId, $site->id, 'site', $site->id, "{$site->name} · ".($meta['label'] ?? $program), "/sites/{$site->id}", $limits->memoryLimit);
    }

    public function process(string $kind, string $id, ?string $serverId = null): ?ProcessOwner
    {
        $process = match ($kind) {
            'worker' => Worker::query()->find(strtolower($id)),
            'daemon' => Daemon::query()->find(strtolower($id)),
            default => null,
        };

        if ($process === null) {
            return null;
        }

        $site = $this->sites->find($process->site_id);

        // A server reporting another server's worker (or one of another organization) names nothing of its own.
        if ($serverId !== null && ($site === null || ! $process->runsOn($serverId) || ! in_array($serverId, $site->serverIds(), true))) {
            return null;
        }

        $label = $process instanceof Worker ? StateCompiler::workerLabel($process) : $process->name;
        $limits = ResourceLimits::fromArray($process->limits);

        return new ProcessOwner($process->organization_id, $process->site_id, $kind, $process->id, ($site !== null ? "{$site->name} · " : '').$label,
            StatusPoller::url($process->site_id, $kind), $limits->memoryLimit);
    }
}
