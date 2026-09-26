<?php

namespace Kiln\Edge\Application\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use Kiln\Edge\Contracts\EdgeRoutes;
use Kiln\Edge\Domain\Enums\ApplyStatus;
use Kiln\Edge\Domain\Enums\InstallStatus;
use Kiln\Edge\Domain\Models\CertificateInstall;
use Kiln\Edge\Domain\Models\ServerState;
use Kiln\Edge\Events\CertificateIssued;
use Kiln\Edge\Events\EdgeApplied;
use Kiln\Fleet\Events\CommandFailed;
use Kiln\Fleet\Events\CommandFinished;

/**
 * Settles edge.caddy.apply / edge.cert.install commands dispatched by Edge.
 */
final class HandleEdgeCommandOutcome implements ShouldQueue
{
    public function __construct(private readonly EdgeRoutes $routes) {}

    public function handleFinished(CommandFinished $event): void
    {
        match ($event->type) {
            'edge.caddy.apply' => $this->applied($event),
            'edge.cert.install' => $this->certificate($event->commandId, $event->serverId, $event->organizationId, $event->result, null),
            default => null,
        };
    }

    public function handleFailed(CommandFailed $event): void
    {
        $reason = $event->error ?: "Command {$event->status}".($event->exitCode !== null ? " (exit code {$event->exitCode})" : '');

        if ($event->type === 'edge.caddy.apply') {
            ServerState::query()->where('server_id', $event->serverId)->where('command_id', $event->commandId)
                ->update(['status' => ApplyStatus::Failed, 'error' => Str::limit($reason, 990), 'updated_at' => now()]);
        } elseif ($event->type === 'edge.cert.install') {
            $this->certificate($event->commandId, $event->serverId, $event->organizationId, null, $reason);
        }
    }

    private function applied(CommandFinished $event): void
    {
        $state = ServerState::query()->where('server_id', $event->serverId)->where('command_id', $event->commandId)->first();

        // Superseded by a newer apply (or unknown): the newer command settles the state.
        if ($state === null) {
            return;
        }

        $result = $event->result ?? [];

        $state->forceFill([
            'status' => ApplyStatus::Applied,
            'config_sha256' => is_string($result['config_sha256'] ?? null) ? $result['config_sha256'] : null,
            'routes' => is_int($result['routes'] ?? null) ? $result['routes'] : null,
            'error' => null,
            'applied_at' => now(),
        ])->save();

        EdgeApplied::dispatch(
            $event->serverId,
            $state->organization_id,
            $event->commandId,
            (string) $state->payload_sha256,
            (bool) ($result['changed'] ?? false),
            $state->config_sha256,
            (int) ($state->routes ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    private function certificate(string $commandId, string $serverId, string $organizationId, ?array $result, ?string $error): void
    {
        $install = CertificateInstall::query()->with('certificate')->where('server_id', $serverId)->where('command_id', $commandId)->first();

        if ($install === null || $install->certificate->organization_id !== $organizationId) {
            return;
        }

        if ($install->status === InstallStatus::Removing) {
            $install->delete();

            return;
        }

        if ($error !== null) {
            $install->forceFill(['status' => InstallStatus::Failed, 'error' => Str::limit($error, 990)])->save();

            return;
        }

        $install->forceFill(['status' => InstallStatus::Installed, 'error' => null, 'installed_at' => now()])->save();

        CertificateIssued::dispatch(
            $install->certificate_id,
            $organizationId,
            $serverId,
            $install->certificate->domains,
            is_string($result['not_after'] ?? null) ? $result['not_after'] : $install->certificate->not_after?->toIso8601String(),
        );

        // Domains using the certificate become routable on this server.
        $this->routes->schedule($serverId);
    }
}
