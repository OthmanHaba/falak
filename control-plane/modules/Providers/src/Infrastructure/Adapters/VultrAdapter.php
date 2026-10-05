<?php

namespace Falak\Providers\Infrastructure\Adapters;

use Falak\Providers\Contracts\Data\Image;
use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Data\Region;
use Falak\Providers\Contracts\Data\Size;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderType;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Vultr API v2 — https://www.vultr.com/api/
 */
final class VultrAdapter extends HttpProviderAdapter
{
    /**
     * @param  array<string, int>  $http
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.vultr.com/v2',
        array $http = [],
    ) {
        parent::__construct($http);
    }

    public function type(): ProviderType
    {
        return ProviderType::Vultr;
    }

    public function verify(): void
    {
        $this->call('GET', 'account');
    }

    public function regions(): array
    {
        return array_map(fn (array $region) => new Region(
            id: (string) $region['id'],
            name: trim(($region['city'] ?? $region['id']).', '.($region['country'] ?? ''), ', '),
            country: isset($region['country']) ? strtoupper((string) $region['country']) : null,
        ), $this->paginate('regions', 'regions'));
    }

    public function sizes(?string $region = null): array
    {
        $sizes = [];

        foreach ($this->paginate('plans', 'plans', ['type' => 'all']) as $plan) {
            $locations = array_map('strval', $plan['locations'] ?? []);

            if ($locations === [] || ($region !== null && ! in_array($region, $locations, true))) {
                continue;
            }

            $sizes[] = new Size(
                id: (string) $plan['id'],
                name: (string) $plan['id'],
                cpus: (int) $plan['vcpu_count'],
                memoryMb: (int) $plan['ram'],
                diskGb: (int) $plan['disk'],
                priceMonthly: isset($plan['monthly_cost']) ? (float) $plan['monthly_cost'] : null,
                regions: $locations,
                arch: str_contains((string) $plan['id'], 'arm') ? 'arm64' : 'amd64',
            );
        }

        return $sizes;
    }

    public function images(): array
    {
        $images = [];

        foreach ($this->paginate('os', 'os') as $os) {
            if (($os['family'] ?? null) !== 'ubuntu' || ! preg_match('/\b(22|24)\.04\b/', (string) $os['name'], $m)) {
                continue;
            }

            $images[] = new Image(
                id: (string) $os['id'],
                name: (string) $os['name'],
                distribution: 'ubuntu',
                version: "{$m[1]}.04",
                arch: ($os['arch'] ?? 'x64') === 'x64' ? 'amd64' : 'arm64',
            );
        }

        return $images;
    }

    public function createServer(MachineSpec $spec): Machine
    {
        $hostname = $this->hostname($spec->name);

        $response = $this->call('POST', 'instances', array_filter([
            'region' => $spec->region,
            'plan' => $spec->size,
            'os_id' => (int) $spec->image,
            'label' => $spec->name,
            'hostname' => $hostname,
            'sshkey_id' => $spec->sshKeyIds === [] ? null : $spec->sshKeyIds,
            'user_data' => $spec->userData !== null ? base64_encode($spec->userData) : null,
            'enable_ipv6' => $spec->ipv6,
            'backups' => 'disabled',
            'tags' => $spec->labels === [] ? null : array_map(fn ($k, $v) => "{$k}:{$v}", array_keys($spec->labels), $spec->labels),
        ], fn ($value) => $value !== null));

        return $this->machine((array) $response?->json('instance'));
    }

    public function getServer(string $id): ?Machine
    {
        $response = $this->call('GET', "instances/{$id}", allowNotFound: true);

        return $response ? $this->machine((array) $response->json('instance')) : null;
    }

    public function destroyServer(string $id): void
    {
        $this->call('DELETE', "instances/{$id}", allowNotFound: true);
    }

    public function uploadSshKey(string $name, string $publicKey): string
    {
        $normalized = SshKey::normalize($publicKey);

        foreach ($this->paginate('ssh-keys', 'ssh_keys') as $key) {
            if (SshKey::equals((string) ($key['ssh_key'] ?? ''), $normalized)) {
                return (string) $key['id'];
            }
        }

        $response = $this->call('POST', 'ssh-keys', ['name' => $name, 'ssh_key' => $normalized]);

        return (string) $response?->json('ssh_key.id');
    }

    public function deleteSshKey(string $id): void
    {
        $this->call('DELETE', "ssh-keys/{$id}", allowNotFound: true);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->apiKey);
    }

    protected function errorMessage(Response $response): string
    {
        return (string) ($response->json('error') ?? $response->reason());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, string $key, array $query = []): array
    {
        $items = [];
        $cursor = null;

        do {
            $response = $this->call('GET', $path, array_filter([...$query, 'per_page' => 500, 'cursor' => $cursor]));
            array_push($items, ...(array) $response?->json($key, []));
            $cursor = $response?->json('meta.links.next') ?: null;
        } while ($cursor !== null);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private function machine(array $instance): Machine
    {
        if (! isset($instance['id'])) {
            throw new ProviderException('Vultr: unexpected response (missing instance id).', $this->type()->value);
        }

        $ip = fn (?string $value) => in_array($value, [null, '', '0.0.0.0', '::'], true) ? null : $value;

        return new Machine(
            id: (string) $instance['id'],
            name: (string) ($instance['label'] ?? ''),
            status: match (true) {
                ($instance['power_status'] ?? null) === 'stopped' && ($instance['status'] ?? null) === 'active' => Machine::STATUS_STOPPED,
                ($instance['status'] ?? null) === 'pending' => Machine::STATUS_PROVISIONING,
                ($instance['status'] ?? null) === 'active' && ($instance['server_status'] ?? 'ok') !== 'ok' => Machine::STATUS_PROVISIONING,
                ($instance['status'] ?? null) === 'active' => Machine::STATUS_RUNNING,
                default => Machine::STATUS_UNKNOWN,
            },
            ipv4: $ip($instance['main_ip'] ?? null),
            ipv6: $ip($instance['v6_main_ip'] ?? null),
            privateIpv4: $ip($instance['internal_ip'] ?? null),
            region: $instance['region'] ?? null,
        );
    }
}
