<?php

namespace Falak\Servers\Infrastructure;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Providers\Contracts\ProviderType;
use Falak\Servers\Contracts\ServerHeaders;
use Falak\Servers\Domain\Models\Server;

final class ServerHeaderPresenter implements ServerHeaders
{
    public function __construct(private readonly AgentDirectory $agents) {}

    public function for(string $serverId): array
    {
        $server = Server::query()->findOrFail($serverId);
        $agent = $this->agents->forServer($server->id);

        return [
            'id' => $server->id,
            'name' => $server->name,
            'type' => $server->type->value,
            'type_label' => $server->type->label(),
            'status' => $server->status->value,
            'status_message' => $server->status_message,
            'provider' => $server->provider,
            'provider_label' => ProviderType::tryFrom($server->provider)?->label() ?? $server->provider,
            'region' => $server->region,
            'ipv4' => $server->ipv4,
            'agent' => $agent ? [
                'status' => $agent->status->value,
                'last_heartbeat_at' => $agent->lastHeartbeatAt?->format(DATE_ATOM),
            ] : null,
        ];
    }
}
