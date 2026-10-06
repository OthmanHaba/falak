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
use Illuminate\Support\Str;

/**
 * Hetzner Cloud API v1 — https://docs.hetzner.cloud/
 */
final class HetznerAdapter extends HttpProviderAdapter
{
    private const UBUNTU_VERSIONS = ['22.04', '24.04', '26.04'];

    /**
     * @param  array<string, int>  $http
     */
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://api.hetzner.cloud/v1',
        array $http = [],
    ) {
        parent::__construct($http);
    }

    public function type(): ProviderType
    {
        return ProviderType::Hetzner;
    }

    public function verify(): void
    {
        $this->call('GET', 'locations', ['per_page' => 1]);
    }

    public function regions(): array
    {
        return array_map(fn (array $location) => new Region(
            id: (string) $location['name'],
            name: trim(($location['city'] ?? '').' ('.($location['description'] ?? $location['name']).')'),
            country: isset($location['country']) ? strtoupper((string) $location['country']) : null,
        ), $this->paginate('locations', 'locations'));
    }

    public function sizes(?string $region = null): array
    {
        $sizes = [];

        foreach ($this->paginate('server_types', 'server_types') as $type) {
            if (! empty($type['deprecation']) || ! empty($type['deprecated'])) {
                continue;
            }

            $prices = collect($type['prices'] ?? []);
            $locations = $prices->pluck('location')->map(fn ($l) => (string) $l)->values()->all();

            if ($region !== null && ! in_array($region, $locations, true)) {
                continue;
            }

            $price = $prices->when($region !== null, fn ($c) => $c->where('location', $region))
                ->map(fn ($p) => (float) ($p['price_monthly']['gross'] ?? 0))
                ->min();

            $sizes[] = new Size(
                id: (string) $type['name'],
                name: strtoupper((string) $type['name']).(isset($type['description']) ? " — {$type['description']}" : ''),
                cpus: (int) $type['cores'],
                memoryMb: (int) round(((float) $type['memory']) * 1024),
                diskGb: (int) $type['disk'],
                priceMonthly: $price !== null ? round((float) $price, 2) : null,
                regions: $locations,
                arch: ($type['architecture'] ?? 'x86') === 'arm' ? 'arm64' : 'amd64',
            );
        }

        return $sizes;
    }

    public function images(): array
    {
        $images = [];

        foreach ($this->paginate('images', 'images', ['type' => 'system', 'status' => 'available']) as $image) {
            if (($image['os_flavor'] ?? null) !== 'ubuntu' || ! in_array($image['os_version'] ?? null, self::UBUNTU_VERSIONS, true)) {
                continue;
            }

            $arch = ($image['architecture'] ?? 'x86') === 'arm' ? 'arm64' : 'amd64';

            $images[] = new Image(
                id: (string) $image['id'],
                name: ($image['description'] ?? $image['name'])." ({$arch})",
                distribution: 'ubuntu',
                version: (string) $image['os_version'],
                arch: $arch,
            );
        }

        return $images;
    }

    public function createServer(MachineSpec $spec): Machine
    {
        $response = $this->call('POST', 'servers', array_filter([
            'name' => $this->hostname($spec->name),
            'server_type' => $spec->size,
            'location' => $spec->region,
            'image' => ctype_digit($spec->image) ? (int) $spec->image : $spec->image,
            'ssh_keys' => array_map(fn (string $id) => ctype_digit($id) ? (int) $id : $id, $spec->sshKeyIds),
            'user_data' => $spec->userData,
            'labels' => $this->labels($spec->labels),
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => $spec->ipv6],
            'start_after_create' => true,
        ], fn ($value) => $value !== null));

        return $this->machine((array) $response?->json('server'));
    }

    public function getServer(string $id): ?Machine
    {
        $response = $this->call('GET', "servers/{$id}", allowNotFound: true);

        return $response ? $this->machine((array) $response->json('server')) : null;
    }

    public function destroyServer(string $id): void
    {
        $this->call('DELETE', "servers/{$id}", allowNotFound: true);
    }

    public function uploadSshKey(string $name, string $publicKey): string
    {
        $fingerprint = SshKey::md5Fingerprint($publicKey);

        $existing = $this->call('GET', 'ssh_keys', ['fingerprint' => $fingerprint])?->json('ssh_keys.0.id');

        if ($existing !== null) {
            return (string) $existing;
        }

        try {
            $response = $this->call('POST', 'ssh_keys', ['name' => $name, 'public_key' => SshKey::normalize($publicKey)]);
        } catch (ProviderException $e) {
            if ($e->status !== 409) {
                throw $e;
            }

            // Name already used by a different key.
            $response = $this->call('POST', 'ssh_keys', ['name' => $name.'-'.Str::lower(Str::random(6)), 'public_key' => SshKey::normalize($publicKey)]);
        }

        return (string) $response?->json('ssh_key.id');
    }

    public function deleteSshKey(string $id): void
    {
        $this->call('DELETE', "ssh_keys/{$id}", allowNotFound: true);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->token);
    }

    protected function errorMessage(Response $response): string
    {
        return (string) ($response->json('error.message') ?? $response->reason());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, string $key, array $query = []): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->call('GET', $path, [...$query, 'page' => $page, 'per_page' => 50]);
            array_push($items, ...(array) $response?->json($key, []));
            $next = $response?->json('meta.pagination.next_page');
            $page = is_int($next) ? $next : null;
        } while ($page !== null);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $server
     */
    private function machine(array $server): Machine
    {
        if (! isset($server['id'])) {
            throw new ProviderException('Hetzner Cloud: unexpected response (missing server id).', $this->type()->value);
        }

        $ipv6 = $server['public_net']['ipv6']['ip'] ?? null;

        return new Machine(
            id: (string) $server['id'],
            name: (string) ($server['name'] ?? ''),
            status: match ($server['status'] ?? null) {
                'initializing', 'starting' => Machine::STATUS_PROVISIONING,
                'running' => Machine::STATUS_RUNNING,
                'off', 'stopping' => Machine::STATUS_STOPPED,
                default => Machine::STATUS_UNKNOWN,
            },
            ipv4: $server['public_net']['ipv4']['ip'] ?? null,
            // Hetzner returns the assigned /64; the server's primary address is ::1 of that network.
            ipv6: is_string($ipv6) ? preg_replace('#::/\d+$#', '::1', $ipv6) : null,
            privateIpv4: $server['private_net'][0]['ip'] ?? null,
            region: $server['datacenter']['location']['name'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $labels
     * @return array<string, string>|null
     */
    private function labels(array $labels): ?array
    {
        $clean = [];

        foreach ($labels as $key => $value) {
            $key = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $key), 0, 63);
            $clean[$key] = substr((string) preg_replace('/[^A-Za-z0-9._-]/', '-', $value), 0, 63);
        }

        return $clean === [] ? null : $clean;
    }
}
