<?php

namespace Falak\Sites\Application;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Sites\Domain\Models\Site;
use Falak\Sites\Domain\Models\SiteTarget;
use Falak\Sites\Events\SiteTargetFailed;
use Falak\Sites\Events\SiteTargetReady;
use Falak\Sites\Infrastructure\CommandPayloads;
use Illuminate\Support\Str;

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

    /**
     * Stop what a container site runs on a server: its blue and green containers (docker), its compose project, or a
     * function's instances and releases. Volumes are always kept: Volumes deletes the ones the user picked.
     */
    public function removeContainers(Site $site, string $serverId): void
    {
        $commands = match ($site->runtime) {
            SiteRuntime::Docker => [
                ['docker.stop', ['name' => "falak-{$site->slug}-blue", 'remove' => true]],
                ['docker.stop', ['name' => "falak-{$site->slug}-green", 'remove' => true]],
            ],
            SiteRuntime::Compose => [
                ['docker.compose.down', ['project' => $site->slug, 'directory' => $site->rootPath()]],
            ],
            SiteRuntime::Function => [
                ['fn.release.remove', ['site' => $site->slug]],
            ],
            default => [],
        };

        foreach ($commands as $i => [$type, $payload]) {
            try {
                $this->agents->dispatch($serverId, $type, $payload, 300, "sites.containers.remove:{$site->id}:{$serverId}:{$i}:".Str::ulid());
            } catch (AgentUnavailable) {
                return; // The server is gone or disconnected; nothing to clean up remotely.
            }
        }
    }

    private function afterUser(SiteTarget $target): void
    {
        $site = $target->site;

        if ($site->runtime === SiteRuntime::PhpFpm && $site->php_version) {
            $payload = CommandPayloads::fpmPool($site, $site->php_version, $this->servers->phpSettings($target->server_id, $site->php_version), limits: ResourceLimits::fromArray($site->limits));
            $this->dispatch($target, SiteTarget::STEP_POOL, 'runtime.fpm.pool', $payload, 300);

            return;
        }

        // Servers provision Node; Bun and Deno are installed where a site needs them.
        $js = match ($site->runtime) {
            SiteRuntime::Bun => ['runtime.bun.install', (string) config('sites.bun_version'), (string) config('sites.bun_mirror')],
            SiteRuntime::Deno => ['runtime.deno.install', (string) config('sites.deno_version'), (string) config('sites.deno_mirror')],
            default => null,
        };

        if ($js !== null) {
            $payload = ['version' => $js[1], 'default' => true];

            if (trim($js[2]) !== '') {
                $payload['mirror'] = rtrim(trim($js[2]), '/');
            }

            // Runtime zips are 30–45 MB from GitHub releases; allow slow links (downloads retry on stalls).
            $this->dispatch($target, SiteTarget::STEP_RUNTIME, $js[0], $payload, 1800);

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
