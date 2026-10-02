<?php

namespace Kiln\Servers\Domain\MachineCheck;

/**
 * Read access to a provision.inspect report (contracts/agent-protocol/commands/provision.inspect.schema.json
 * $defs.result). Missing sections read as empty: a detector that failed on the agent leaves its part out.
 */
final readonly class MachineReport
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}

    public function hostname(): string
    {
        return (string) ($this->data['hostname'] ?? '');
    }

    public function inContainer(): bool
    {
        return (bool) ($this->data['in_container'] ?? false);
    }

    /**
     * @return array{name: string, version: string, origin: string, repo?: string, label?: string}|null
     */
    public function package(string $name): ?array
    {
        foreach ($this->list('packages') as $package) {
            if (($package['name'] ?? null) === $name) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Installed packages whose name matches one of the regular expressions.
     *
     * @param  list<string>  $patterns
     * @return list<array<string, mixed>>
     */
    public function packagesMatching(array $patterns): array
    {
        return array_values(array_filter($this->list('packages'), function (array $package) use ($patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, (string) ($package['name'] ?? '')) === 1) {
                    return true;
                }
            }

            return false;
        }));
    }

    public function hasSnap(string $name): bool
    {
        foreach ($this->list('snaps') as $snap) {
            if (($snap['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an enabled apt source points at a host (e.g. download.docker.com).
     */
    public function hasAptSource(string $host): bool
    {
        foreach ($this->list('apt_sources') as $source) {
            foreach ((array) ($source['uris'] ?? []) as $uri) {
                if (str_contains((string) $uri, $host)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{unit: string, active: string, enabled: string}|null
     */
    public function service(string $unit): ?array
    {
        foreach ($this->list('services') as $service) {
            if (($service['unit'] ?? null) === $unit) {
                return $service;
            }
        }

        return null;
    }

    public function serviceActive(string $unit): bool
    {
        return ($this->service($unit)['active'] ?? null) === 'active';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listenersOn(int $port): array
    {
        return array_values(array_filter($this->list('listeners'), fn (array $l) => (int) ($l['port'] ?? -1) === $port));
    }

    /**
     * Containers that publish a host port.
     *
     * @return list<array<string, mixed>>
     */
    public function containersPublishing(int $port): array
    {
        return array_values(array_filter($this->list('containers'), function (array $c) use ($port) {
            foreach ((array) ($c['ports'] ?? []) as $p) {
                if ((int) ($p['host_port'] ?? -1) === $port) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function docker(): ?array
    {
        return is_array($this->data['docker'] ?? null) ? $this->data['docker'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function section(string $key): array
    {
        return is_array($this->data[$key] ?? null) ? $this->data[$key] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(string $key): array
    {
        return array_values(array_filter((array) ($this->data[$key] ?? []), 'is_array'));
    }

    /**
     * The upstream part of a Debian version: "5:28.1.1-1~ubuntu.24.04~noble" → "28.1.1", "1:10.11.8-0ubuntu0.24.04.1" → "10.11.8".
     */
    public static function upstream(?string $version): ?string
    {
        if ($version === null || $version === '') {
            return null;
        }

        $version = preg_replace('/^\d+:/', '', $version);

        return preg_match('/^(\d+(?:\.\d+)*)/', (string) $version, $m) === 1 ? $m[1] : null;
    }

    /**
     * Where an installed package comes from, for people ("Ubuntu archive", "download.docker.com", "manual install").
     *
     * @param  array<string, mixed>  $package
     */
    public static function sourceOf(array $package): string
    {
        return match ($package['origin'] ?? null) {
            'archive' => trim(((string) ($package['label'] ?? '')) ?: 'Ubuntu').' archive',
            'vendor' => (string) (parse_url((string) ($package['repo'] ?? ''), PHP_URL_HOST) ?: ($package['label'] ?? 'another repository')),
            default => 'manual install',
        };
    }
}
