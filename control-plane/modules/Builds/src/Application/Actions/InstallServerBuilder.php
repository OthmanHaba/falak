<?php

namespace Kiln\Builds\Application\Actions;

use Kiln\Builds\Domain\Models\Builder;
use Kiln\Fleet\Contracts\AgentGateway;
use Kiln\Fleet\Contracts\Exceptions\AgentUnavailable;
use Kiln\Identity\Contracts\AuditLog;
use Kiln\Servers\Contracts\Data\ServerData;

/**
 * Register a `builder` server as a build worker: issue a builder token and let the agent install
 * kiln-builder as a systemd service polling this control plane. Re-running rotates the token.
 */
final class InstallServerBuilder
{
    public const ENV_PATH = '/etc/kiln/builder.env';

    public const UNIT_PATH = '/etc/systemd/system/kiln-builder.service';

    public function __construct(
        private readonly AgentGateway $agents,
        private readonly AuditLog $audit,
    ) {}

    public function __invoke(ServerData $server, ?string $actorId = null): Builder
    {
        $token = Builder::newToken();

        /** @var Builder $builder */
        $builder = Builder::query()->updateOrCreate(['server_id' => $server->id], [
            'organization_id' => $server->organizationId,
            'name' => $server->name,
            'kind' => Builder::KIND_SERVER,
            'token_hash' => Builder::hashToken($token),
            'modes' => ['native', 'docker'],
            'enabled' => true,
        ]);

        $key = "builds:builder:{$builder->id}:".substr(Builder::hashToken($token), 0, 16);

        try {
            $this->agents->dispatch($server->id, 'system.write_file', [
                'path' => self::ENV_PATH,
                'content' => $this->environment($builder, $token),
                'mode' => '0600',
                'owner' => 'root',
            ], 60, "{$key}:env");

            $this->agents->dispatch($server->id, 'system.write_file', [
                'path' => self::UNIT_PATH,
                'content' => self::unit(),
                'mode' => '0644',
            ], 60, "{$key}:unit");

            $handle = $this->agents->dispatch($server->id, 'system.exec', ['script' => $this->installScript(), 'user' => 'root'], 600, "{$key}:install");
            $builder->forceFill(['install_command_id' => $handle->id])->save();
        } catch (AgentUnavailable) {
            // The token is issued; installation is retried from the Builders page once the agent is back.
        }

        $this->audit->record('builds.builder_installed', 'server', $server->id, ['builder_id' => $builder->id], $server->organizationId, $actorId);

        return $builder;
    }

    private function environment(Builder $builder, string $token): string
    {
        $url = rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/');

        return implode("\n", [
            "KILN_URL={$url}",
            "KILN_BUILDER_TOKEN={$token}",
            'KILN_BUILDER_NAME='.preg_replace('/[^A-Za-z0-9._-]/', '-', $builder->name),
            'KILN_BUILDER_DIR=/var/lib/kiln-builder',
            '',
        ]);
    }

    public static function downloadUrl(): string
    {
        $configured = (string) config('builds.builder_binary.download_url');

        return $configured !== '' ? $configured : rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/').'/install/builder/linux-{arch}';
    }

    private function installScript(): string
    {
        $url = escapeshellarg(self::downloadUrl());

        return <<<SH
            set -euo pipefail
            arch=\$(uname -m); case "\$arch" in x86_64) arch=amd64 ;; aarch64|arm64) arch=arm64 ;; esac
            url={$url}; url="\${url//\{arch\}/\$arch}"
            curl -fsSL --retry 3 -o /usr/local/bin/kiln-builder.tmp "\$url"
            chmod 0755 /usr/local/bin/kiln-builder.tmp && mv /usr/local/bin/kiln-builder.tmp /usr/local/bin/kiln-builder
            mkdir -p /var/lib/kiln-builder
            systemctl daemon-reload
            systemctl enable kiln-builder >/dev/null
            systemctl restart kiln-builder
            SH;
    }

    public static function unit(): string
    {
        return <<<'UNIT'
            [Unit]
            Description=Kiln build worker
            After=network-online.target docker.service
            Wants=network-online.target

            [Service]
            EnvironmentFile=/etc/kiln/builder.env
            ExecStart=/usr/local/bin/kiln-builder serve
            Restart=always
            RestartSec=5
            KillMode=mixed
            TimeoutStopSec=120

            [Install]
            WantedBy=multi-user.target

            UNIT;
    }
}
