<?php

namespace Kiln\Servers\Infrastructure;

use Illuminate\Support\Str;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Stack\Stack;

/**
 * Builds the full desired state for `provision.apply`
 * (contracts/agent-protocol/commands/provision.apply.schema.json) from a server's type and stack.
 *
 * The schema has no first-class database / cache / Docker sections, so those are expressed as apt
 * packages plus systemd service state; engine-level configuration belongs to the Databases module.
 */
final class ProvisioningPlanBuilder
{
    /**
     * @param  array<string, mixed>  $config  the `servers` config array
     */
    public function __construct(private readonly array $config) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Server $server): array
    {
        $stack = $server->stack;
        $type = $server->type;
        $phpVersions = $server->desiredPhpVersions() ?: $stack->phpVersions;
        $defaultPhp = $server->phpVersions()->where('is_default', true)->value('version') ?? $stack->phpDefault;

        [$packages, $services] = $this->packagesAndServices($stack);

        $plan = [
            'hostname' => $this->hostname($server->name),
            'timezone' => $server->timezone ?: 'UTC',
            'swap_mb' => $this->swapMb($server->memory_bytes),
            'apt' => ['packages' => $packages],
            'users' => [$this->unixUser($stack)],
            'runtimes' => $this->runtimes($type, $stack, $phpVersions, $defaultPhp),
            'services' => $services,
            'unattended_upgrades' => ['enabled' => true, 'auto_reboot' => false, 'reboot_time' => '04:00'],
            'ssh' => ['port' => $server->ssh_port, 'permit_root_login' => 'prohibit-password', 'password_authentication' => false],
        ];

        if ($plan['runtimes'] === []) {
            unset($plan['runtimes']);
        }

        if ($plan['services'] === []) {
            unset($plan['services']);
        }

        return $plan;
    }

    /**
     * RFC 1123 hostname derived from the server name.
     */
    public function hostname(string $name): string
    {
        $hostname = trim((string) preg_replace('/-+/', '-', (string) preg_replace('/[^a-z0-9-]/', '-', Str::lower(Str::ascii($name)))), '-');
        $hostname = substr($hostname, 0, 63);

        return trim($hostname, '-') ?: 'kiln-server';
    }

    public function swapMb(?int $memoryBytes): int
    {
        if ($memoryBytes === null) {
            return 2048;
        }

        foreach ($this->config['swap'] ?? [] as [$below, $megabytes]) {
            if ($memoryBytes < $below) {
                return (int) $megabytes;
            }
        }

        return 0;
    }

    /**
     * @return array{0: list<string>, 1: list<array{name: string, enabled: bool, state: string}>}
     */
    private function packagesAndServices(Stack $stack): array
    {
        $packages = (array) ($this->config['base_packages'] ?? []);
        $services = [['name' => 'fail2ban', 'enabled' => true, 'state' => 'started']];

        foreach ([['databases', $stack->database], ['caches', $stack->cache]] as [$group, $engine]) {
            if ($engine === null) {
                continue;
            }

            $definition = $this->config[$group][$engine];
            $packages = [...$packages, ...$definition['packages']];
            $services[] = ['name' => $definition['service'], 'enabled' => true, 'state' => 'started'];
        }

        if ($stack->docker) {
            $packages = [...$packages, ...$this->config['docker']['packages']];
            $services[] = ['name' => $this->config['docker']['service'], 'enabled' => true, 'state' => 'started'];
        }

        $packages = array_values(array_unique($packages));
        sort($packages);

        return [$packages, $services];
    }

    /**
     * @return array<string, mixed>
     */
    private function unixUser(Stack $stack): array
    {
        $user = (string) ($this->config['unix_user'] ?? 'kiln');
        $groups = ['www-data'];

        if ($stack->docker) {
            $groups[] = 'docker';
        }

        return [
            'name' => $user,
            'shell' => '/bin/bash',
            'home' => "/home/{$user}",
            'groups' => $groups,
            'sudo' => 'none',
        ];
    }

    /**
     * @param  list<string>  $phpVersions
     * @return array<string, mixed>
     */
    private function runtimes(ServerType $type, Stack $stack, array $phpVersions, ?string $defaultPhp): array
    {
        $runtimes = [];

        if ($stack->phpRuntime !== null && $phpVersions !== []) {
            sort($phpVersions);

            $runtimes['php'] = [
                'versions' => array_values($phpVersions),
                'default' => in_array($defaultPhp, $phpVersions, true) ? $defaultPhp : end($phpVersions),
                'extensions' => array_values((array) ($this->config['php_extensions'] ?? [])),
                'fpm' => $stack->phpRuntime === 'fpm',
            ];

            if ($stack->phpRuntime === 'frankenphp') {
                $runtimes['frankenphp'] = array_filter([
                    'version' => (string) $this->config['frankenphp']['version'],
                    'sha256' => $this->config['frankenphp']['sha256'] ?? null,
                ]);
            }
        }

        if ($stack->node !== null) {
            $version = (string) $this->config['node_versions'][$stack->node];
            $runtimes['node'] = ['versions' => [$version], 'default' => $version];
        }

        // FrankenPHP embeds Caddy; standalone Caddy fronts PHP-FPM sites and load balancers.
        if ($type->servesHttp()) {
            $runtimes['caddy'] = ['enabled' => $stack->phpRuntime !== 'frankenphp'];
        }

        return $runtimes;
    }
}
