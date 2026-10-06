<?php

namespace Falak\Sites\Application\Listeners;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Fleet\Events\AgentVersionChanged;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Infrastructure\CommandPayloads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Pools are only written when a site is provisioned. An upgraded agent keeps .env files on the tmpfs, which isolated
 * pools' open_basedir must allow (PHP checks the resolved path): re-apply them before the next deploy needs it.
 */
final class ReapplyFpmPools implements ShouldQueue
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
    ) {}

    public function handle(AgentVersionChanged $event): void
    {
        if ($event->serverId === null) {
            return;
        }

        $sites = Site::query()
            ->where('runtime', SiteRuntime::PhpFpm->value)
            ->where('isolated', true)
            ->whereNotNull('php_version')
            ->whereHas('targets', fn ($q) => $q->where('server_id', $event->serverId))
            ->get();

        foreach ($sites as $site) {
            $payload = CommandPayloads::fpmPool($site, (string) $site->php_version, $this->servers->phpSettings($event->serverId, (string) $site->php_version));

            try {
                $this->agents->dispatch($event->serverId, 'runtime.fpm.pool', $payload, 300, "sites.pool.reapply:{$site->id}:{$event->serverId}:".Str::ulid());
            } catch (AgentUnavailable) {
                return;
            }
        }
    }
}
