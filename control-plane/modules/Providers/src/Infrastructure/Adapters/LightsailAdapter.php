<?php

namespace Kiln\Providers\Infrastructure\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kiln\Providers\Contracts\Data\Image;
use Kiln\Providers\Contracts\Data\Machine;
use Kiln\Providers\Contracts\Data\MachineSpec;
use Kiln\Providers\Contracts\Data\Region;
use Kiln\Providers\Contracts\Data\Size;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Providers\Infrastructure\Aws\SigV4Signer;
use stdClass;

/**
 * AWS Lightsail via its JSON 1.1 API, signed with SigV4.
 *
 * Lightsail addresses instances by name within a region, so machine ids are "<region>:<instanceName>"
 * and SSH key ids are "<region>:<keyPairName>". Key pairs are regional: a key uploaded in another region
 * than the instance is not attached at create time (the agent syncs authorized keys after enrollment).
 */
final class LightsailAdapter extends HttpProviderAdapter
{
    private const TARGET_PREFIX = 'Lightsail_20161128.';

    private const BLUEPRINTS = ['ubuntu_22_04' => '22.04', 'ubuntu_24_04' => '24.04'];

    private string $currentRegion;

    /**
     * @param  array<string, int>  $http
     */
    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
        private readonly string $endpointTemplate = 'https://lightsail.{region}.amazonaws.com',
        array $http = [],
    ) {
        parent::__construct($http);
        $this->currentRegion = $region;
    }

    public function type(): ProviderType
    {
        return ProviderType::Aws;
    }

    public function verify(): void
    {
        $this->op('GetRegions', ['includeAvailabilityZones' => false]);
    }

    public function regions(): array
    {
        return array_map(fn (array $region) => new Region(
            id: (string) $region['name'],
            name: trim(($region['displayName'] ?? $region['name']).' ('.$region['name'].')'),
        ), (array) $this->op('GetRegions', ['includeAvailabilityZones' => false])?->json('regions', []));
    }

    public function sizes(?string $region = null): array
    {
        $sizes = [];

        foreach ($this->paginate('GetBundles', 'bundles', ['includeInactive' => false], $region) as $bundle) {
            if (! ($bundle['isActive'] ?? true) || ! in_array('LINUX_UNIX', $bundle['supportedPlatforms'] ?? ['LINUX_UNIX'], true)) {
                continue;
            }

            $sizes[] = new Size(
                id: (string) $bundle['bundleId'],
                name: (string) ($bundle['name'] ?? $bundle['bundleId']).' — '.$bundle['bundleId'],
                cpus: (int) $bundle['cpuCount'],
                memoryMb: (int) round(((float) $bundle['ramSizeInGb']) * 1024),
                diskGb: (int) $bundle['diskSizeInGb'],
                priceMonthly: isset($bundle['price']) ? (float) $bundle['price'] : null,
                regions: $region !== null ? [$region] : [],
            );
        }

        return $sizes;
    }

    public function images(): array
    {
        $images = [];

        foreach ($this->paginate('GetBlueprints', 'blueprints', ['includeInactive' => false]) as $blueprint) {
            $version = self::BLUEPRINTS[$blueprint['blueprintId'] ?? ''] ?? null;

            if ($version === null || ! ($blueprint['isActive'] ?? true)) {
                continue;
            }

            $images[] = new Image(
                id: (string) $blueprint['blueprintId'],
                name: trim(($blueprint['name'] ?? 'Ubuntu').' '.($blueprint['version'] ?? $version)),
                distribution: 'ubuntu',
                version: $version,
                arch: 'amd64',
            );
        }

        return $images;
    }

    public function createServer(MachineSpec $spec): Machine
    {
        $name = $this->instanceName($spec->name);

        $keyPair = collect($spec->sshKeyIds)
            ->map(fn (string $id) => explode(':', $id, 2))
            ->first(fn (array $parts) => count($parts) === 2 && $parts[0] === $spec->region);

        $this->op('CreateInstances', array_filter([
            'instanceNames' => [$name],
            'availabilityZone' => $spec->region.'a',
            'blueprintId' => $spec->image,
            'bundleId' => $spec->size,
            'userData' => $spec->userData,
            'keyPairName' => $keyPair[1] ?? null,
            'ipAddressType' => $spec->ipv6 ? 'dualstack' : 'ipv4',
            'tags' => $spec->labels === [] ? null : array_map(fn ($k, $v) => ['key' => (string) $k, 'value' => $v], array_keys($spec->labels), $spec->labels),
        ], fn ($value) => $value !== null), $spec->region);

        return new Machine(id: "{$spec->region}:{$name}", name: $name, status: Machine::STATUS_PROVISIONING, region: $spec->region);
    }

    public function getServer(string $id): ?Machine
    {
        [$region, $name] = $this->splitId($id);

        $instance = $this->op('GetInstance', ['instanceName' => $name], $region, allowNotFound: true)?->json('instance');

        if (! is_array($instance)) {
            return null;
        }

        return new Machine(
            id: $id,
            name: (string) $instance['name'],
            status: match ($instance['state']['name'] ?? null) {
                'pending', 'starting', 'rebooting' => Machine::STATUS_PROVISIONING,
                'running' => Machine::STATUS_RUNNING,
                'stopped', 'stopping' => Machine::STATUS_STOPPED,
                default => Machine::STATUS_UNKNOWN,
            },
            ipv4: $instance['publicIpAddress'] ?? null,
            ipv6: $instance['ipv6Addresses'][0] ?? null,
            privateIpv4: $instance['privateIpAddress'] ?? null,
            region: $instance['location']['regionName'] ?? $region,
        );
    }

    public function destroyServer(string $id): void
    {
        [$region, $name] = $this->splitId($id);

        $this->op('DeleteInstance', ['instanceName' => $name, 'forceDeleteAddOns' => true], $region, allowNotFound: true);
    }

    public function uploadSshKey(string $name, string $publicKey): string
    {
        $normalized = SshKey::normalize($publicKey);
        // Deterministic per key material, so re-uploading the same key is a no-op.
        $keyPairName = substr((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name), 0, 40).'-'.substr(hash('sha256', $normalized), 0, 12);

        foreach ($this->paginate('GetKeyPairs', 'keyPairs', ['includeDefaultKeyPair' => false]) as $pair) {
            if (($pair['name'] ?? null) === $keyPairName) {
                return "{$this->region}:{$keyPairName}";
            }
        }

        $this->op('ImportKeyPair', ['keyPairName' => $keyPairName, 'publicKeyBase64' => $normalized]);

        return "{$this->region}:{$keyPairName}";
    }

    public function deleteSshKey(string $id): void
    {
        [$region, $name] = $this->splitId($id);

        $this->op('DeleteKeyPair', ['keyPairName' => $name], $region, allowNotFound: true);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->endpoint($this->currentRegion));
    }

    protected function errorMessage(Response $response): string
    {
        $type = Str::afterLast((string) ($response->json('__type') ?? ''), '#');
        $message = (string) ($response->json('message') ?? $response->json('Message') ?? $response->reason());

        return $type !== '' ? "{$type}: {$message}" : $message;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function op(string $operation, array $payload, ?string $region = null, bool $allowNotFound = false): ?Response
    {
        $region ??= $this->region;
        $this->currentRegion = $region;
        $url = rtrim($this->endpoint($region), '/').'/';
        $body = json_encode($payload === [] ? new stdClass : $payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $idempotent = str_starts_with($operation, 'Get') || str_starts_with($operation, 'Delete');

        $headers = (new SigV4Signer($this->accessKeyId, $this->secretAccessKey, $region, 'lightsail'))->sign('POST', $url, [
            'Content-Type' => 'application/x-amz-json-1.1',
            'X-Amz-Target' => self::TARGET_PREFIX.$operation,
        ], $body, Carbon::now());

        try {
            $response = $this->prepare(Http::withHeaders($headers), $idempotent)
                ->withBody($body, 'application/x-amz-json-1.1')
                ->post($url);
        } catch (ConnectionException $e) {
            throw new ProviderException("AWS Lightsail API is unreachable: {$e->getMessage()}", $this->type()->value, null, $e);
        }

        if ($response->failed()) {
            if ($allowNotFound && str_contains((string) $response->json('__type'), 'NotFoundException')) {
                return null;
            }

            throw $this->exception($response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function paginate(string $operation, string $key, array $payload = [], ?string $region = null): array
    {
        $items = [];
        $token = null;

        do {
            $response = $this->op($operation, array_filter([...$payload, 'pageToken' => $token], fn ($v) => $v !== null), $region);
            array_push($items, ...(array) $response?->json($key, []));
            $token = $response?->json('nextPageToken') ?: null;
        } while ($token !== null);

        return $items;
    }

    private function endpoint(string $region): string
    {
        return str_replace('{region}', $region, $this->endpointTemplate);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitId(string $id): array
    {
        $parts = explode(':', $id, 2);

        return count($parts) === 2 ? [$parts[0], $parts[1]] : [$this->region, $id];
    }

    /** Lightsail instance names: letters, digits, dashes, underscores, dots; must start with a letter/digit. */
    private function instanceName(string $name): string
    {
        return substr(ltrim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', $name), '-_.'), 0, 255) ?: 'kiln-server';
    }
}
