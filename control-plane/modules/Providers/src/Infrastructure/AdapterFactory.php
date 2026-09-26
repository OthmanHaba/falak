<?php

namespace Kiln\Providers\Infrastructure;

use Illuminate\Contracts\Config\Repository as Config;
use Kiln\Providers\Contracts\Exceptions\ProviderException;
use Kiln\Providers\Contracts\ProviderAdapter;
use Kiln\Providers\Contracts\ProviderType;
use Kiln\Providers\Infrastructure\Adapters\CustomAdapter;
use Kiln\Providers\Infrastructure\Adapters\DigitalOceanAdapter;
use Kiln\Providers\Infrastructure\Adapters\HetznerAdapter;
use Kiln\Providers\Infrastructure\Adapters\LightsailAdapter;
use Kiln\Providers\Infrastructure\Adapters\LinodeAdapter;
use Kiln\Providers\Infrastructure\Adapters\VultrAdapter;

final class AdapterFactory
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  array<string, mixed>  $credentials  decrypted credential fields
     */
    public function make(ProviderType $type, array $credentials): ProviderAdapter
    {
        /** @var array<string, int> $http */
        $http = (array) $this->config->get('providers.http', []);
        $endpoint = fn (string $default) => (string) $this->config->get("providers.endpoints.{$type->value}", $default);
        $field = function (string $name) use ($credentials, $type): string {
            $value = $credentials[$name] ?? null;

            if (! is_string($value) || $value === '') {
                throw new ProviderException("Missing credential field [{$name}] for {$type->label()}.", $type->value);
            }

            return $value;
        };

        return match ($type) {
            ProviderType::Hetzner => new HetznerAdapter($field('token'), $endpoint('https://api.hetzner.cloud/v1'), $http),
            ProviderType::DigitalOcean => new DigitalOceanAdapter($field('token'), $endpoint('https://api.digitalocean.com/v2'), $http),
            ProviderType::Vultr => new VultrAdapter($field('api_key'), $endpoint('https://api.vultr.com/v2'), $http),
            ProviderType::Linode => new LinodeAdapter($field('token'), $endpoint('https://api.linode.com/v4'), $http),
            ProviderType::Aws => new LightsailAdapter(
                $field('access_key_id'),
                $field('secret_access_key'),
                $field('region'),
                $endpoint('https://lightsail.{region}.amazonaws.com'),
                $http,
            ),
            ProviderType::Custom => new CustomAdapter,
        };
    }
}
