<?php

namespace Falak\Providers\Infrastructure\Adapters;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Falak\Providers\Contracts\Data\Image;
use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Data\Region;
use Falak\Providers\Contracts\Data\Size;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderType;

/**
 * DigitalOcean API v2 — https://docs.digitalocean.com/reference/api/
 */
final class DigitalOceanAdapter extends HttpProviderAdapter
{
    private const COUNTRIES = [
        'nyc' => 'US', 'sfo' => 'US', 'atl' => 'US', 'ams' => 'NL', 'sgp' => 'SG', 'lon' => 'GB',
        'fra' => 'DE', 'tor' => 'CA', 'blr' => 'IN', 'syd' => 'AU',
    ];

    /**
     * @param  array<string, int>  $http
     */
    public function __construct(
        private readonly string $token,
        private readonly string $baseUrl = 'https://api.digitalocean.com/v2',
        array $http = [],
    ) {
        parent::__construct($http);
    }

    public function type(): ProviderType
    {
        return ProviderType::DigitalOcean;
    }

    public function verify(): void
    {
        $this->call('GET', 'account');
    }

    public function regions(): array
    {
        return array_map(fn (array $region) => new Region(
            id: (string) $region['slug'],
            name: (string) $region['name'],
            country: self::COUNTRIES[substr((string) $region['slug'], 0, 3)] ?? null,
            available: (bool) ($region['available'] ?? true),
        ), $this->paginate('regions', 'regions'));
    }

    public function sizes(?string $region = null): array
    {
        $sizes = [];

        foreach ($this->paginate('sizes', 'sizes') as $size) {
            $regions = array_map('strval', $size['regions'] ?? []);

            if (! ($size['available'] ?? true) || ($region !== null && ! in_array($region, $regions, true))) {
                continue;
            }

            $sizes[] = new Size(
                id: (string) $size['slug'],
                name: (string) ($size['description'] ?? $size['slug']).' — '.$size['slug'],
                cpus: (int) $size['vcpus'],
                memoryMb: (int) $size['memory'],
                diskGb: (int) $size['disk'],
                priceMonthly: isset($size['price_monthly']) ? (float) $size['price_monthly'] : null,
                regions: $regions,
            );
        }

        return $sizes;
    }

    public function images(): array
    {
        $images = [];

        foreach ($this->paginate('images', 'images', ['type' => 'distribution']) as $image) {
            $slug = (string) ($image['slug'] ?? '');

            if (($image['distribution'] ?? null) !== 'Ubuntu' || ! preg_match('/^ubuntu-(22|24)-04-x64$/', $slug, $m)) {
                continue;
            }

            $images[] = new Image(id: $slug, name: 'Ubuntu '.$image['name'], distribution: 'ubuntu', version: "{$m[1]}.04", arch: 'amd64');
        }

        return $images;
    }

    public function createServer(MachineSpec $spec): Machine
    {
        $response = $this->call('POST', 'droplets', array_filter([
            'name' => $this->hostname($spec->name),
            'region' => $spec->region,
            'size' => $spec->size,
            'image' => $spec->image,
            'ssh_keys' => array_map(fn (string $id) => ctype_digit($id) ? (int) $id : $id, $spec->sshKeyIds),
            'user_data' => $spec->userData,
            'ipv6' => $spec->ipv6,
            'monitoring' => false,
            'tags' => $this->tags($spec->labels),
        ], fn ($value) => $value !== null));

        return $this->machine((array) $response?->json('droplet'));
    }

    public function getServer(string $id): ?Machine
    {
        $response = $this->call('GET', "droplets/{$id}", allowNotFound: true);

        return $response ? $this->machine((array) $response->json('droplet')) : null;
    }

    public function destroyServer(string $id): void
    {
        $this->call('DELETE', "droplets/{$id}", allowNotFound: true);
    }

    public function uploadSshKey(string $name, string $publicKey): string
    {
        $existing = $this->call('GET', 'account/keys/'.SshKey::md5Fingerprint($publicKey), allowNotFound: true);

        if ($existing !== null) {
            return (string) $existing->json('ssh_key.id');
        }

        $response = $this->call('POST', 'account/keys', ['name' => $name, 'public_key' => SshKey::normalize($publicKey)]);

        return (string) $response?->json('ssh_key.id');
    }

    public function deleteSshKey(string $id): void
    {
        $this->call('DELETE', "account/keys/{$id}", allowNotFound: true);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)->withToken($this->token);
    }

    protected function errorMessage(Response $response): string
    {
        return (string) ($response->json('message') ?? $response->reason());
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
            $response = $this->call('GET', $path, [...$query, 'page' => $page, 'per_page' => 200]);
            array_push($items, ...(array) $response?->json($key, []));
            $page = $response?->json('links.pages.next') ? $page + 1 : null;
        } while ($page !== null);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $droplet
     */
    private function machine(array $droplet): Machine
    {
        if (! isset($droplet['id'])) {
            throw new ProviderException('DigitalOcean: unexpected response (missing droplet id).', $this->type()->value);
        }

        $v4 = collect($droplet['networks']['v4'] ?? []);
        $v6 = collect($droplet['networks']['v6'] ?? []);

        return new Machine(
            id: (string) $droplet['id'],
            name: (string) ($droplet['name'] ?? ''),
            status: match ($droplet['status'] ?? null) {
                'new' => Machine::STATUS_PROVISIONING,
                'active' => Machine::STATUS_RUNNING,
                'off' => Machine::STATUS_STOPPED,
                default => Machine::STATUS_UNKNOWN,
            },
            ipv4: $v4->firstWhere('type', 'public')['ip_address'] ?? null,
            ipv6: $v6->firstWhere('type', 'public')['ip_address'] ?? null,
            privateIpv4: $v4->firstWhere('type', 'private')['ip_address'] ?? null,
            region: $droplet['region']['slug'] ?? null,
        );
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<string>|null
     */
    private function tags(array $labels): ?array
    {
        $tags = [];

        foreach ($labels as $key => $value) {
            $tags[] = substr((string) preg_replace('/[^A-Za-z0-9:_-]/', '-', "{$key}:{$value}"), 0, 255);
        }

        return $tags === [] ? null : $tags;
    }
}
