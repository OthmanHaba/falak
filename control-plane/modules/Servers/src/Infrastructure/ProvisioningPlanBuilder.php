<?php

namespace Kiln\Servers\Infrastructure;

use Illuminate\Support\Str;
use Kiln\Servers\Contracts\ServerType;
use Kiln\Servers\Domain\Enums\PhpVersionStatus;
use Kiln\Servers\Domain\MachineCheck\ComponentDecision;
use Kiln\Servers\Domain\MachineCheck\Decision;
use Kiln\Servers\Domain\MachineCheck\MachineCheck;
use Kiln\Servers\Domain\Models\Server;
use Kiln\Servers\Domain\Stack\Stack;

/**
 * Builds the full desired state for `provision.apply`
 * (contracts/agent-protocol/commands/provision.apply.schema.json) from a server's type and stack.
 *
 * The schema has no first-class database / cache / Docker sections, so those are expressed as apt
 * packages plus systemd service state; engine-level configuration belongs to the Databases module.
 *
 * With a machine check (agents with provision.v2) the plan follows its decisions: adopted and blocked components
 * install nothing (an adopted engine keeps its service entry), completed ones install only their missing packages, an
 * adopted swap / hostname is left out, and `components` tells the agent what was adopted.
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
    public function build(Server $server, ?MachineCheck $check = null): array
    {
        $stack = $server->stack;
        $type = $server->type;
        $phpVersions = $server->desiredPhpVersions() ?: $stack->phpVersions;
        // Never plan a version the OS cannot install (PhpVersionsForOs has already fitted the server's records);
        // versions already on the host stay.
        $installed = $server->phpVersions()->where('status', PhpVersionStatus::Installed)->pluck('version')->map(fn ($v) => (string) $v)->all();
        $phpVersions = array_values(array_filter($phpVersions, fn (string $v) => in_array($v, $server->installablePhpVersions(), true) || in_array($v, $installed, true)));
        $defaultPhp = $server->phpVersions()->where('is_default', true)->value('version') ?? $stack->phpDefault;

        [$packages, $services] = $this->packagesAndServices($stack, $check);

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

        if ($check !== null) {
            if ($this->decided($check, 'swap', Decision::Adopt, Decision::Skip)) {
                unset($plan['swap_mb']);
            }

            if ($this->decided($check, 'hostname', Decision::Adopt)) {
                unset($plan['hostname']);
            }

            $plan['components'] = $this->components($check);
        }

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
    private function packagesAndServices(Stack $stack, ?MachineCheck $check): array
    {
        $packages = (array) ($this->config['base_packages'] ?? []);
        $services = [['name' => 'fail2ban', 'enabled' => true, 'state' => 'started']];

        foreach ([['databases', $stack->database, 'database'], ['caches', $stack->cache, 'cache']] as [$group, $engine, $component]) {
            if ($engine === null) {
                continue;
            }

            $definition = $this->config[$group][$engine];
            [$install, $service] = $this->decidedPackages($check?->for($component), $definition['packages'], $definition['service']);
            $packages = [...$packages, ...$install];

            if ($service !== null) {
                $services[] = ['name' => $service, 'enabled' => true, 'state' => 'started'];
            }
        }

        if ($stack->docker) {
            [$install, $service] = $this->decidedPackages($check?->for('docker'), $this->config['docker']['packages'], $this->config['docker']['service']);
            $packages = [...$packages, ...$install];

            if ($service !== null) {
                $services[] = ['name' => $service, 'enabled' => true, 'state' => 'started'];
            }
        }

        $packages = array_values(array_unique($packages));
        sort($packages);

        return [$packages, $services];
    }

    /**
     * Packages to install and the service to run for a component after its machine-check decision (none: as before).
     *
     * @param  list<string>  $packages
     * @return array{0: list<string>, 1: ?string}
     */
    private function decidedPackages(?ComponentDecision $decision, array $packages, string $service): array
    {
        return match ($decision?->decision) {
            null, Decision::Install => [$packages, $service],
            Decision::Adopt => [[], $decision->service ?? $service],
            Decision::Complete => [$decision->install, $decision->service ?? $service],
            Decision::Block, Decision::Skip => [[], null],
        };
    }

    private function decided(MachineCheck $check, string $component, Decision ...$decisions): bool
    {
        return in_array($check->for($component)?->decision, $decisions, true);
    }

    /**
     * provision.apply `components`: the decision per component, with the packages an adopted one is made of (verified,
     * never installed) or a completed one adds.
     *
     * @return list<array<string, mixed>>
     */
    private function components(MachineCheck $check): array
    {
        $components = [];

        foreach ($check->components as $decision) {
            if (! in_array($decision->decision, [Decision::Install, Decision::Adopt, Decision::Complete], true)) {
                continue;
            }

            $packages = match ($decision->decision) {
                Decision::Adopt => $decision->keep,
                Decision::Complete => $decision->install,
                default => [],
            };

            $components[] = array_filter([
                'name' => $decision->component,
                'decision' => $decision->decision->value,
                'packages' => array_values(array_unique($packages)) ?: null,
                'service' => $decision->service,
            ], fn ($value) => $value !== null);
        }

        return $components;
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
                    'mirror' => $this->mirror('frankenphp'),
                ]);
            }
        }

        if ($stack->node !== null) {
            $version = (string) $this->config['node_versions'][$stack->node];
            $runtimes['node'] = array_filter(['versions' => [$version], 'default' => $version, 'mirror' => $this->mirror('node')]);
        }

        // FrankenPHP embeds Caddy; standalone Caddy fronts PHP-FPM sites and load balancers.
        if ($type->servesHttp()) {
            $runtimes['caddy'] = ['enabled' => $stack->phpRuntime !== 'frankenphp'];
        }

        return $runtimes;
    }

    private function mirror(string $runtime): ?string
    {
        $mirror = trim((string) ($this->config['mirrors'][$runtime] ?? ''));

        return $mirror === '' ? null : rtrim($mirror, '/');
    }
}
