<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kiln\Fleet\Contracts\AgentStatus;
use Kiln\Fleet\Contracts\CommandStatus;
use Kiln\Fleet\Domain\Models\Agent;
use Kiln\Fleet\Domain\Models\Command;
use Kiln\Network\Domain\Enums\ApplyStatus;
use Kiln\Network\Domain\Enums\KeyStatus;
use Kiln\Network\Domain\Enums\RuleAction;
use Kiln\Network\Domain\Enums\RuleProtocol;
use Kiln\Network\Domain\Models\FirewallRule;
use Kiln\Network\Domain\Models\FirewallState;
use Kiln\Network\Domain\Models\PrivateNetwork;
use Kiln\Network\Domain\Models\PrivateNetworkMember;
use Kiln\Recipes\Domain\Enums\RunStatus;
use Kiln\Recipes\Domain\Enums\TargetStatus as RunTargetStatus;
use Kiln\Recipes\Domain\Models\Recipe;
use Kiln\Recipes\Domain\Models\Run;
use Kiln\Recipes\Domain\Models\RunTarget;
use Kiln\Servers\Application\Actions\AttachSshKey;
use Kiln\Servers\Application\Actions\CreateSshKey;
use Kiln\Servers\Contracts\ServerStatus;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\Models\PhpVersion;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Stack\Stack;
use Kiln\Terminal\Domain\Enums\SessionStatus;
use Kiln\Terminal\Domain\Models\TerminalFrame;
use Kiln\Terminal\Domain\Models\TerminalSession;

/**
 * Realistic infrastructure for the UI demo (called by UiDemoSeeder): agents with heartbeat metrics, PHP versions,
 * SSH keys, firewalls, a private network, recipe runs, a terminal recording, a provisioning log and a custom server
 * waiting for its agent. Written directly against module models — local demo data only.
 */
class InfrastructureDemoSeeder extends Seeder
{
    private const GB = 1024 ** 3;

    public function run(string $organizationId, string $userId): void
    {
        $servers = Server::query()->where('organization_id', $organizationId)->get()->keyBy('name');
        $now = now();

        $specs = [
            'app-1' => ['hetzner', 'fsn1', 'cpx31', '49.12.40.11', '10.0.0.2', 4, 8, 160, AgentStatus::Online, 0.34, 0.52],
            'app-2' => ['hetzner', 'fsn1', 'cpx31', '49.12.40.12', '10.0.0.3', 4, 8, 160, AgentStatus::Offline, 0.22, 0.41],
            'db-1' => ['hetzner', 'nbg1', 'cpx41', '49.12.51.20', '10.0.0.4', 8, 16, 240, AgentStatus::Online, 0.18, 0.71],
            'worker-1' => ['digitalocean', 'ams3', 's-2vcpu-4gb', '167.99.12.40', null, 2, 4, 80, AgentStatus::Online, 0.61, 0.38],
            'edge-1' => ['vultr', 'fra', 'vc2-1c-2gb', '45.77.61.3', null, 1, 2, 55, null, 0, 0],
        ];

        foreach ($specs as $name => [$provider, $region, $size, $ipv4, $private, $cpus, $memory, $disk, $agentStatus, $cpuBase, $memBase]) {
            $server = $servers[$name] ?? null;

            if (! $server) {
                continue;
            }

            $server->forceFill([
                'provider' => $provider,
                'region' => $region,
                'size' => $size,
                'image' => 'ubuntu-24.04',
                'ipv4' => $ipv4,
                'ipv6' => '2a01:4f8:c012:'.substr(md5($name), 0, 4).'::1',
                'private_ipv4' => $private,
                'provider_server_id' => (string) random_int(40000000, 49999999),
                'os' => 'ubuntu 24.04',
                'arch' => 'amd64',
                'cpus' => $cpus,
                'memory_bytes' => $memory * self::GB,
                'disk_bytes' => $disk * self::GB,
                'provisioned_at' => $server->status === ServerStatus::Active ? $now->copy()->subDays(12) : null,
                'status_message' => $name === 'edge-1' ? 'provision.apply failed: apt-get install caddy exited with 100 (unable to fetch archive).' : null,
            ])->save();

            if ($agentStatus === null) {
                continue;
            }

            $lastHeartbeat = $agentStatus === AgentStatus::Online ? $now->copy()->subSeconds(8) : $now->copy()->subMinutes(23);
            $agent = Agent::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $server->id,
                'status' => $agentStatus,
                'hostname' => $name,
                'arch' => 'amd64',
                'agent_version' => '1.4.2',
                'facts' => ['kernel' => '6.8.0-45-generic', 'docker' => $name === 'worker-1' ? '27.3.1' : null, 'runtimes' => ['php' => ['8.3', '8.4']]],
                'metrics' => [
                    'at' => $lastHeartbeat->toIso8601String(),
                    'uptime_s' => 12 * 86400 + 3 * 3600,
                    'load' => [round($cpuBase * $cpus, 2), round($cpuBase * $cpus * 0.9, 2), round($cpuBase * $cpus * 0.8, 2)],
                    'cpu_percent' => round($cpuBase * 100, 1),
                    'memory_used_bytes' => (int) ($memBase * $memory * self::GB),
                    'disk_used_bytes' => (int) (0.42 * $disk * self::GB),
                ],
                'enrolled_at' => $now->copy()->subDays(12),
                'last_heartbeat_at' => $lastHeartbeat,
            ]);

            $this->metrics($agent->id, $server->id, $cpuBase, $memBase, $memory * self::GB, $disk * self::GB, $cpus, $agentStatus === AgentStatus::Online ? $now : $lastHeartbeat);
        }

        $this->php($servers);
        $this->provisioningLog($organizationId, $servers['worker-1'] ?? null);
        $this->customServer($organizationId);
        $this->sshKeys($organizationId, $userId, $servers);
        $this->firewalls($organizationId, $servers);
        $this->privateNetwork($organizationId, $servers);
        $this->recipes($organizationId, $userId, $servers);
        $this->terminal($organizationId, $userId, $servers['app-1'] ?? null);
    }

    /** One sample per minute for the last 24 hours, with a gentle daily wave and noise. */
    private function metrics(string $agentId, string $serverId, float $cpuBase, float $memBase, int $memory, int $disk, int $cpus, Carbon $until): void
    {
        $rows = [];

        for ($minute = 24 * 60; $minute >= 0; $minute--) {
            $at = $until->copy()->subMinutes($minute);
            $wave = sin($minute / 180) * 0.12 + (mt_rand(-50, 50) / 1000);
            $cpu = max(0.02, min(0.97, $cpuBase + $wave));
            $rows[] = [
                'agent_id' => $agentId,
                'server_id' => $serverId,
                'at' => $at,
                'uptime_s' => 12 * 86400,
                'load1' => round($cpu * $cpus, 2),
                'load5' => round($cpu * $cpus * 0.92, 2),
                'load15' => round($cpu * $cpus * 0.85, 2),
                'cpu_percent' => round($cpu * 100, 1),
                'memory_used_bytes' => (int) (max(0.1, min(0.95, $memBase + $wave / 3)) * $memory),
                'disk_used_bytes' => (int) ((0.40 + (24 * 60 - $minute) / (24 * 60) * 0.02) * $disk),
            ];

            if (count($rows) === 500) {
                DB::table('fleet_agent_metrics')->insert($rows);
                $rows = [];
            }
        }

        DB::table('fleet_agent_metrics')->insert($rows);
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function php($servers): void
    {
        foreach (['app-1' => ['8.3', '8.4'], 'app-2' => ['8.4'], 'worker-1' => ['8.4']] as $name => $versions) {
            $server = $servers[$name] ?? null;

            if (! $server) {
                continue;
            }

            foreach ($versions as $version) {
                PhpVersion::query()->create([
                    'server_id' => $server->id,
                    'version' => $version,
                    'status' => PhpVersionStatus::Installed,
                    'is_default' => $version === '8.4',
                    'ini' => $version === '8.4' ? ['memory_limit' => '512M', 'upload_max_filesize' => '64M', 'post_max_size' => '64M'] : [],
                    'fpm' => PhpVersion::defaultFpm($server->memory_bytes),
                ]);
            }
        }
    }

    private function provisioningLog(string $organizationId, ?Server $server): void
    {
        if (! $server) {
            return;
        }

        $agentId = Agent::query()->where('server_id', $server->id)->value('id');
        $started = now()->subMinutes(3);
        $command = Command::query()->create([
            'organization_id' => $organizationId,
            'agent_id' => $agentId,
            'server_id' => $server->id,
            'type' => 'provision.apply',
            'payload' => '{}',
            'timeout_s' => 1800,
            'idempotency_key' => "provision:{$server->id}:1",
            'status' => CommandStatus::Running,
            'attempts' => 1,
            'queued_at' => $started->copy()->subSeconds(4),
            'delivered_at' => $started->copy()->subSeconds(2),
            'started_at' => $started,
        ]);

        $lines = [
            "\e[1m==> Preparing system\e[0m",
            'Hit:1 http://mirror.hetzner.com/ubuntu/packages noble InRelease',
            'Reading package lists... Done',
            "\e[1m==> Creating kiln user\e[0m",
            'useradd: kiln (uid 1001) created',
            "\e[1m==> Installing PHP 8.4 (FrankenPHP)\e[0m",
            'Setting up frankenphp (1.4.4) ...',
            "\e[32m✓\e[0m frankenphp.service enabled",
            "\e[1m==> Installing Docker Engine\e[0m",
            'Setting up docker-ce (5:27.3.1-1~ubuntu.24.04~noble) ...',
            "\e[33mwarning:\e[0m br_netfilter module not loaded, loading it now",
            "\e[1m==> Configuring supervisor\e[0m",
        ];

        foreach ($lines as $seq => $line) {
            DB::table('fleet_command_events')->insert([
                'command_id' => $command->id,
                'seq' => $seq,
                'kind' => 'output',
                'stream' => str_contains($line, 'warning') ? 'stderr' : 'stdout',
                'data' => $line."\n",
                'at' => $started->copy()->addSeconds($seq * 9),
            ]);
        }

        $server->forceFill(['provision_command_id' => $command->id])->save();
    }

    private function customServer(string $organizationId): void
    {
        Server::query()->create([
            'organization_id' => $organizationId,
            'name' => 'office-nuc',
            'type' => ServerType::Worker,
            'status' => ServerStatus::Creating,
            'provider' => 'custom',
            'timezone' => 'Europe/Amsterdam',
            'stack' => Stack::defaultsFor(ServerType::Worker),
            'install_command' => 'curl -fsSL https://panel.kiln.test/install/'.Str::lower(Str::random(40)).' | sudo bash',
        ]);
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function sshKeys(string $organizationId, string $userId, $servers): void
    {
        $create = app(CreateSshKey::class);
        $attach = app(AttachSshKey::class);

        $laptop = $create($organizationId, $userId, 'ada@laptop', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH2FPCLaNIa5y0nRNSFX+6ChIQ9P5jPmbmIsK+bmeo5s ada@laptop');
        $ci = $create($organizationId, $userId, 'GitHub Actions deploy', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEZON12LwqEThsky8ygzEZmjL875DO3VY+su/31D7zKl ci@deploy');

        foreach (['app-1', 'app-2', 'db-1'] as $name) {
            if (isset($servers[$name])) {
                $attach($servers[$name], $laptop, 'kiln');
            }
        }

        if (isset($servers['app-1'])) {
            $attach($servers['app-1'], $ci, 'kiln');
            $attach($servers['app-1'], $laptop, 'root');
        }
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function firewalls(string $organizationId, $servers): void
    {
        foreach ($servers as $server) {
            $rules = [['SSH', '22', null]];

            if (in_array($server->type, [ServerType::App, ServerType::Web, ServerType::LoadBalancer], true)) {
                $rules[] = ['HTTP', '80', null];
                $rules[] = ['HTTPS', '443', null];
            }

            foreach ($rules as $position => [$name, $port, $source]) {
                FirewallRule::query()->create([
                    'organization_id' => $organizationId,
                    'server_id' => $server->id,
                    'name' => $name,
                    'action' => RuleAction::Allow,
                    'protocol' => RuleProtocol::Tcp,
                    'port' => $port,
                    'source' => $source,
                    'is_default' => true,
                    'position' => $position,
                ]);
            }

            $active = $server->status === ServerStatus::Active;
            FirewallState::query()->create([
                'server_id' => $server->id,
                'organization_id' => $organizationId,
                'revision' => $active ? 3 : 0,
                'desired_hash' => $active ? str_repeat('a', 64) : null,
                'applied_hash' => $active ? str_repeat('a', 64) : null,
                'status' => $active ? ApplyStatus::Applied : ApplyStatus::Pending,
                'ruleset_sha256' => $active ? hash('sha256', $server->id) : null,
                'applied_at' => $active ? now()->subDays(2) : null,
            ]);
        }

        if (isset($servers['db-1'])) {
            FirewallRule::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $servers['db-1']->id,
                'name' => 'PostgreSQL from app servers',
                'action' => RuleAction::Allow,
                'protocol' => RuleProtocol::Tcp,
                'port' => '5432',
                'source' => '10.0.0.0/24',
                'is_default' => false,
                'position' => 5,
            ]);
            FirewallRule::query()->create([
                'organization_id' => $organizationId,
                'server_id' => $servers['db-1']->id,
                'name' => 'Block scanner',
                'action' => RuleAction::Deny,
                'protocol' => RuleProtocol::Any,
                'port' => null,
                'source' => '203.0.113.0/24',
                'is_default' => false,
                'position' => 6,
            ]);
        }
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function privateNetwork(string $organizationId, $servers): void
    {
        $network = PrivateNetwork::query()->create([
            'organization_id' => $organizationId,
            'name' => 'backend',
            'cidr' => '10.90.0.0/24',
            'interface' => 'kiln0',
            'listen_port' => 51820,
        ]);
        PrivateNetwork::query()->create([
            'organization_id' => $organizationId,
            'name' => 'observability',
            'cidr' => '10.91.0.0/24',
            'interface' => 'kiln1',
            'listen_port' => 51821,
        ]);

        foreach (['app-1', 'app-2', 'db-1'] as $index => $name) {
            if (! isset($servers[$name])) {
                continue;
            }

            PrivateNetworkMember::query()->create([
                'network_id' => $network->id,
                'organization_id' => $organizationId,
                'server_id' => $servers[$name]->id,
                'address' => '10.90.0.'.($index + 1),
                'public_key' => base64_encode(random_bytes(32)),
                'key_status' => KeyStatus::Installed,
                'status' => $name === 'app-2' ? ApplyStatus::Pending : ApplyStatus::Applied,
                'revision' => 2,
                'applied_at' => $name === 'app-2' ? null : now()->subDays(3),
            ]);
        }
    }

    /**
     * @param  Collection<string, Server>  $servers
     */
    private function recipes(string $organizationId, string $userId, $servers): void
    {
        $recipe = Recipe::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Clear OPcache',
            'description' => 'Reload FrankenPHP workers so fresh code and ini changes take effect.',
            'script' => "#!/usr/bin/env bash\nset -euo pipefail\nsystemctl reload frankenphp\necho \"opcache cleared on \$(hostname)\"",
            'user' => 'root',
        ]);
        Recipe::query()->create([
            'organization_id' => $organizationId,
            'name' => 'Disk usage report',
            'description' => 'Largest directories under /srv and /var.',
            'script' => 'du -xh /srv /var 2>/dev/null | sort -rh | head -20',
            'user' => 'kiln',
        ]);

        $runs = [
            [['app-1', 'app-2'], [RunTargetStatus::Succeeded, RunTargetStatus::Unavailable], RunStatus::Partial, 90],
            [['app-1'], [RunTargetStatus::Succeeded], RunStatus::Succeeded, 30],
            [['app-1', 'db-1'], [RunTargetStatus::Failed, RunTargetStatus::Succeeded], RunStatus::Partial, 5],
        ];

        foreach ($runs as [$names, $statuses, $status, $minutesAgo]) {
            $at = now()->subMinutes($minutesAgo);
            $run = Run::query()->create([
                'organization_id' => $organizationId,
                'recipe_id' => $recipe->id,
                'recipe_name' => $recipe->name,
                'script' => $recipe->script,
                'user' => $recipe->user,
                'env' => [],
                'timeout_s' => 900,
                'requested_by' => $userId,
                'status' => $status,
                'started_at' => $at,
                'finished_at' => $at->copy()->addSeconds(4),
                'created_at' => $at,
                'updated_at' => $at,
            ]);

            foreach ($names as $index => $name) {
                if (! isset($servers[$name])) {
                    continue;
                }

                $targetStatus = $statuses[$index];
                RunTarget::query()->create([
                    'run_id' => $run->id,
                    'organization_id' => $organizationId,
                    'server_id' => $servers[$name]->id,
                    'server_name' => $name,
                    'status' => $targetStatus,
                    'exit_code' => match ($targetStatus) {
                        RunTargetStatus::Succeeded => 0,
                        RunTargetStatus::Failed => 1,
                        default => null,
                    },
                    'error' => match ($targetStatus) {
                        RunTargetStatus::Failed => 'Job for frankenphp.service failed because the control process exited with error code.',
                        RunTargetStatus::Unavailable => 'The server agent is not connected.',
                        default => null,
                    },
                    'duration_ms' => $targetStatus === RunTargetStatus::Unavailable ? null : random_int(900, 3800),
                    'started_at' => $at,
                    'finished_at' => $at->copy()->addSeconds(3),
                ]);
            }
        }
    }

    private function terminal(string $organizationId, string $userId, ?Server $server): void
    {
        if (! $server) {
            return;
        }

        $opened = now()->subHours(2);
        $session = TerminalSession::query()->create([
            'organization_id' => $organizationId,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'user_id' => $userId,
            'unix_user' => 'kiln',
            'status' => SessionStatus::Closed,
            'cols' => 120,
            'rows' => 32,
            'initial_cols' => 120,
            'initial_rows' => 32,
            'idle_timeout_s' => 900,
            'shared' => false,
            'channel_epoch' => 1,
            'started_at' => $opened,
            'last_activity_at' => $opened->copy()->addMinutes(4),
            'closed_at' => $opened->copy()->addMinutes(4),
            'close_reason' => 'exited',
            'exit_code' => 0,
            'recording_bytes' => 0,
            'created_at' => $opened,
            'updated_at' => $opened,
        ]);

        $output = [
            [0, "\e[32mkiln@app-1\e[0m:\e[34m~\e[0m$ "],
            [900, "uptime\r\n"],
            [1100, " 14:02:11 up 12 days,  3:04,  1 user,  load average: 0.41, 0.38, 0.35\r\n"],
            [1200, "\e[32mkiln@app-1\e[0m:\e[34m~\e[0m$ "],
            [3200, "df -h /\r\n"],
            [3400, "Filesystem      Size  Used Avail Use% Mounted on\r\n/dev/sda1       152G   64G   82G  44% /\r\n"],
            [3500, "\e[32mkiln@app-1\e[0m:\e[34m~\e[0m$ "],
            [6000, "exit\r\nlogout\r\n"],
        ];
        $bytes = 0;

        foreach ($output as $seq => [$offset, $text]) {
            TerminalFrame::query()->create([
                'session_id' => $session->id,
                'fleet_seq' => $seq,
                'kind' => TerminalFrame::OUTPUT,
                'offset_ms' => $offset,
                'data' => base64_encode($text),
            ]);
            $bytes += strlen($text);
        }

        $session->forceFill(['recording_bytes' => $bytes])->save();
    }
}
