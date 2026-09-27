<?php

namespace Kiln\Sites\Application;

use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Servers\Contracts\ServerDirectory;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Sites\Contracts\TargetStatus;
use Kiln\Sites\Domain\Models\Site;
use Kiln\Sites\Domain\Models\SiteTarget;
use Kiln\Sites\Events\SiteTargetFailed;
use Kiln\Sites\Events\SiteTargetReady;
use Kiln\Sites\Infrastructure\CommandPayloads;

/**
 * Prepares a site on one server, one agent command at a time:
 * unix user (isolated sites) → PHP-FPM pool (php-fpm runtime) or JS runtime (bun/deno) → ready.
 * Each step is advanced by the command outcome listener.
 */
final class TargetProvisioner
{
    public function __construct(
        private readonly AgentGateway $agents,
        private readonly ServerDirectory $servers,
    ) {}

    public function start(SiteTarget $target): void
    {
        $site = $target->site;

        if ($site->isolated) {
            $this->dispatch($target, SiteTarget::STEP_USER, 'system.user.create', CommandPayloads::user($site), 300);

            return;
        }

        $this->afterUser($target);
    }

    /**
     * The command for $target's current step succeeded.
     */
    public function advance(SiteTarget $target): void
    {
        match ($target->step) {
            SiteTarget::STEP_USER => $this->afterUser($target),
            default => $this->ready($target),
        };
    }

    public function fail(SiteTarget $target, string $reason): void
    {
        $target->forceFill(['status' => TargetStatus::Failed, 'status_message' => Str::limit($reason, 990), 'command_id' => null])->save();

        SiteTargetFailed::dispatch($target->site_id, $target->site->organization_id, $target->server_id, $target->id, (string) $target->status_message);
    }

    /**
     * Remove the site's PHP-FPM pool from the server (best-effort; site files are kept).
     */
    public function removePool(Site $site, string $serverId, string $phpVersion): void
    {
        try {
            $this->agents->dispatch($serverId, 'runtime.fpm.pool', CommandPayloads::fpmPool($site, $phpVersion, null, 'absent'), 300, "sites.pool.remove:{$site->id}:{$serverId}:".Str::ulid());
        } catch (AgentUnavailable) {
            // The server is gone or disconnected; nothing to clean up remotely.
        }
    }

    private function afterUser(SiteTarget $target): void
    {
        $site = $target->site;

        if ($site->runtime === SiteRuntime::PhpFpm && $site->php_version) {
            $payload = CommandPayloads::fpmPool($site, $site->php_version, $this->servers->phpSettings($target->server_id, $site->php_version));
            $this->dispatch($target, SiteTarget::STEP_POOL, 'runtime.fpm.pool', $payload, 300);

            return;
        }

        // Servers provision Node; Bun and Deno are installed where a site needs them.
        $js = match ($site->runtime) {
            SiteRuntime::Bun => ['runtime.bun.install', (string) config('sites.bun_version')],
            SiteRuntime::Deno => ['runtime.deno.install', (string) config('sites.deno_version')],
            default => null,
        };

        if ($js !== null) {
            // Runtime zips are 30–45 MB from GitHub releases; allow slow links (downloads retry on stalls).
            $this->dispatch($target, SiteTarget::STEP_RUNTIME, $js[0], ['version' => $js[1], 'default' => true], 1800);

            return;
        }

        $this->ready($target);
    }

    private function ready(SiteTarget $target): void
    {
        $target->forceFill(['status' => TargetStatus::Ready, 'status_message' => null, 'step' => null, 'command_id' => null])->save();

        SiteTargetReady::dispatch($target->site_id, $target->site->organization_id, $target->server_id, $target->id);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(SiteTarget $target, string $step, string $type, array $payload, int $timeout): void
    {
        try {
            $handle = $this->agents->dispatch($target->server_id, $type, $payload, $timeout, "sites.target:{$target->id}:{$step}:".Str::ulid());
        } catch (AgentUnavailable) {
            $this->fail($target, 'The server agent is not connected.');

            return;
        }

        $target->forceFill(['status' => TargetStatus::Provisioning, 'status_message' => null, 'step' => $step, 'command_id' => $handle->id])->save();
    }
}
