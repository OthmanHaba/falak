<?php

namespace Falak\Processes\Infrastructure;

use Falak\Limits\Contracts\LimitDefaults;
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
        private readonly LimitDefaults $defaults,
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
            return $this->process($kind, $meta['ref_id']);
        }

        $site = $this->sites->find($meta['site_id']);

        if ($site === null) {
            return null;
        }

        $limits = $this->defaults->effective($site->limits, $site->id);

        return new ProcessOwner($site->organizationId, $site->id, 'site', $site->id, "{$site->name} · ".($meta['label'] ?? $program), "/sites/{$site->id}", $limits->memoryLimit);
    }

    public function process(string $kind, string $id): ?ProcessOwner
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
        $label = $process instanceof Worker ? StateCompiler::workerLabel($process) : $process->name;
        $limits = $this->defaults->effective(ResourceLimits::fromArray($process->limits), $process->site_id);

        return new ProcessOwner($process->organization_id, $process->site_id, $kind, $process->id, ($site !== null ? "{$site->name} · " : '').$label,
            StatusPoller::url($process->site_id, $kind), $limits->memoryLimit);
    }
}
