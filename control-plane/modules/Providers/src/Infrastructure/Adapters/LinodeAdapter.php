<?php

namespace Kiln\Providers\Infrastructure\Adapters;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kiln\Providers\Contracts\Data\Image;
use Kiln\Providers\Contracts\Data\Machine;
use Kiln\Providers\Contracts\Data\MachineSpec;
use Kiln\Providers\Contracts\Data\Region;
use Kiln\Providers\Contracts\Data\Size;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderType;

/**
 * Akamai Connected Cloud (Linode) API v4 — https://techdocs.akamai.com/linode-api/reference/api
 *
 * Linode takes raw public keys at create time (`authorized_keys`), so uploaded keys live in the
 * account's profile (/profile/sshkeys) and createServer resolves the ids back to key material.
 * A random root password is required by the API; it is generated per server and never stored or logged
 * (access is key-based; the agent manages users).
 */
final class LinodeAdapter extends HttpProviderAdapter
{
    private const IMAGES = ['linode/ubuntu22.04' => '22.04', 'linode/ubuntu24.04' => '24.04'];

    /**
     * @param  array<string, int>  $http
     */
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://api.linode.com/v4',
        array $http = [],
    ) {
        parent::__construct($http);
    }

    public function type(): ProviderType
    {
        return ProviderType::Linode;
    }

    public function verify(): void
    {
        $this->call('GET', 'profile');
    }

    public function regions(): array
    {
        return array_map(fn (array $region) => new Region(
            id: (string) $region['id'],
            name: (string) ($region['label'] ?? $region['id']),
            country: isset($region['country']) ? strtoupper((string) $region['country']) : null,
            available: ($region['status'] ?? 'ok') === 'ok',
        ), $this->paginate('regions'));
    }

    public function sizes(?string $region = null): array
    {
        return array_map(function (array $type) use ($region) {
            $price = $type['price']['monthly'] ?? null;

            foreach ($type['region_prices'] ?? [] as $regional) {
                if ($region !== null && ($regional['id'] ?? null) === $region) {
                    $price = $regional['monthly'] ?? $price;
                }
            }

            return new Size(
                id: (string) $type['id'],
                name: (string) ($type['label'] ?? $type['id']),
                cpus: (int) $type['vcpus'],
                memoryMb: (int) $type['memory'],
                diskGb: (int) round(((int) $type['disk']) / 1024),
                priceMonthly: $price !== null ? (float) $price : null,
            );
        }, $this->paginate('linode/types'));
    }

    public function images(): array
    {
        $images = [];

        foreach ($this->paginate('images') as $image) {
            $version = self::IMAGES[$image['id'] ?? ''] ?? null;

            if ($version === null || ! empty($image['deprecated'])) {
                continue;
            }

            $images[] = new Image(id: (string) $image['id'], name: (string) $image['label'], distribution: 'ubuntu', version: $version, arch: 'amd64');
        }

        return $images;
    }

    public function createServer(MachineSpec $spec): Machine
    {
        $keys = array_map(
            fn (string $id) => (string) $this->call('GET', "profile/sshkeys/{$id}")?->json('ssh_key'),
            $spec->sshKeyIds,
        );

        $response = $this->call('POST', 'linode/instances', array_filter([
            'label' => $this->label($spec->name),
            'region' => $spec->region,
            'type' => $spec->size,
            'image' => $spec->image,
            'root_pass' => $this->rootPassword(),
            'authorized_keys' => $keys === [] ? null : $keys,
            'metadata' => $spec->userData !== null ? ['user_data' => base64_encode($spec->userData)] : null,
            'tags' => $spec->labels === [] ? null : array_map(fn ($k, $v) => substr("{$k}:{$v}", 0, 50), array_keys($spec->labels), $spec->labels),
            'booted' => true,
        ], fn ($value) => $value !== null));

        return $this->machine((array) $response?->json());
    }

    public function getServer(string $id): ?Machine
    {
        $response = $this->call('GET', "linode/instances/{$id}", allowNotFound: true);

        return $response ? $this->machine((array) $response->json()) : null;
    }

    public function destroyServer(string $id): void
    {
        $this->call('DELETE', "linode/instances/{$id}", allowNotFound: true);
    }

    public function uploadSshKey(string $name, string $publicKey): string
    {
        $normalized = SshKey::normalize($publicKey);

        foreach ($this->paginate('profile/sshkeys') as $key) {
            if (SshKey::equals((string) ($key['ssh_key'] ?? ''), $normalized)) {
                return (string) $key['id'];
            }
        }

        $response = $this->call('POST', 'profile/sshkeys', ['label' => substr($name, 0, 64), 'ssh_key' => $normalized]);

        return (string) $response?->json('id');
    }

    public function deleteSshKey(string $id): void
    {
        $this->call('DELETE', "profile/sshkeys/{$id}", allowNotFound: true);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->token);
    }

    protected function errorMessage(Response $response): string
    {
        return collect((array) $response->json('errors', []))
            ->map(fn ($error) => trim((isset($error['field']) ? "{$error['field']}: " : '').($error['reason'] ?? '')))
            ->filter()
            ->implode('; ') ?: $response->reason();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->call('GET', $path, ['page' => $page, 'page_size' => 500]);
            array_push($items, ...(array) $response?->json('data', []));
            $pages = (int) ($response?->json('pages') ?? 1);
            $page++;
        } while ($page <= $pages);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private function machine(array $instance): Machine
    {
        if (! isset($instance['id'])) {
            throw new ProviderException('Akamai / Linode: unexpected response (missing instance id).', $this->type()->value);
        }

        $ipv4 = collect($instance['ipv4'] ?? []);
        $isPrivate = fn (string $ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
        $ipv6 = $instance['ipv6'] ?? null;

        return new Machine(
            id: (string) $instance['id'],
            name: (string) ($instance['label'] ?? ''),
            status: match ($instance['status'] ?? null) {
                'provisioning', 'booting', 'rebooting' => Machine::STATUS_PROVISIONING,
                'running' => Machine::STATUS_RUNNING,
                'offline', 'shutting_down', 'stopped' => Machine::STATUS_STOPPED,
                default => Machine::STATUS_UNKNOWN,
            },
            ipv4: $ipv4->first(fn ($ip) => ! $isPrivate((string) $ip)),
            ipv6: is_string($ipv6) ? explode('/', $ipv6)[0] : null,
            privateIpv4: $ipv4->first(fn ($ip) => $isPrivate((string) $ip)),
            region: $instance['region'] ?? null,
        );
    }

    /** Linode labels: 3–64 chars of letters, digits, dashes, underscores and dots. */
    private function label(string $name): string
    {
        // Linode labels: 3-64 chars, alphanumeric start/end, no consecutive "-", "_" or ".".
        $label = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $label = (string) preg_replace('/([._-])[._-]+/', '$1', $label);
        $label = trim(substr(trim($label, '-._'), 0, 64), '-._');

        return str_pad($label, 3, '0');
    }

    private function rootPassword(): string
    {
        // Mixed classes satisfy Linode's password strength check.
        return Str::password(48, symbols: false).'aA1!';
    }
}
