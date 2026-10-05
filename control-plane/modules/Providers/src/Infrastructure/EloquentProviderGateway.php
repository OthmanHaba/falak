<?php

namespace Falak\Providers\Infrastructure;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Falak\Providers\Contracts\Data\CredentialSummary;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderAdapter;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Providers\Domain\Models\ProviderCredential;

final class EloquentProviderGateway implements ProviderGateway
{
    public function __construct(
        private readonly AdapterFactory $factory,
        private readonly Cache $cache,
        private readonly Config $config,
    ) {}

    public function credentials(string $organizationId): array
    {
        return ProviderCredential::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get()
            ->map(fn (ProviderCredential $credential) => $credential->toSummary())
            ->values()
            ->all();
    }

    public function credential(string $organizationId, string $credentialId): ?CredentialSummary
    {
        return $this->find($organizationId, $credentialId)?->toSummary();
    }

    public function adapter(string $organizationId, string $credentialId): ProviderAdapter
    {
        $credential = $this->find($organizationId, $credentialId)
            ?? throw new ProviderException('Provider credential not found in this organization.');

        return $this->factory->make($credential->provider, $credential->credentials);
    }

    public function regions(string $organizationId, string $credentialId): array
    {
        return $this->cached($organizationId, $credentialId, 'regions', fn (ProviderAdapter $adapter) => $adapter->regions());
    }

    public function sizes(string $organizationId, string $credentialId, ?string $region = null): array
    {
        return $this->cached($organizationId, $credentialId, 'sizes:'.($region ?? '*'), fn (ProviderAdapter $adapter) => $adapter->sizes($region));
    }

    public function images(string $organizationId, string $credentialId): array
    {
        return $this->cached($organizationId, $credentialId, 'images', fn (ProviderAdapter $adapter) => $adapter->images());
    }

    /**
     * @template T
     *
     * @param  callable(ProviderAdapter): list<T>  $fetch
     * @return list<T>
     */
    private function cached(string $organizationId, string $credentialId, string $kind, callable $fetch): array
    {
        $credential = $this->find($organizationId, $credentialId)
            ?? throw new ProviderException('Provider credential not found in this organization.');

        // Rotating or renaming the credential bumps updated_at, which invalidates its catalog cache.
        $key = sprintf('providers:catalog:%s:%d:%s', $credential->id, $credential->updated_at?->getTimestamp() ?? 0, $kind);

        return $this->cache->remember(
            $key,
            (int) $this->config->get('providers.catalog_cache_ttl', 3600),
            fn () => $fetch($this->factory->make($credential->provider, $credential->credentials)),
        );
    }

    private function find(string $organizationId, string $credentialId): ?ProviderCredential
    {
        return ProviderCredential::query()->forOrganization($organizationId)->find($credentialId);
    }
}
