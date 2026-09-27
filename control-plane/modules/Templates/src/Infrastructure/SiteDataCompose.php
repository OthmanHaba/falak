<?php

namespace Kiln\Templates\Infrastructure;

use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Sites\Contracts\SiteRuntime;
use Kiln\Templates\Application\Compose\SiteCompose;

/**
 * {@see SiteCompose} over the compose fields lane A adds to `Sites\Contracts\Data\SiteData` (compose source /
 * content / public services). Read tolerantly (camelCase or snake_case, arrays or objects) because that DTO is
 * being extended in parallel; tighten to the real properties once lane A has merged.
 */
final class SiteDataCompose implements SiteCompose
{
    public function content(SiteData $site): ?string
    {
        if ($site->runtime !== SiteRuntime::Compose) {
            return null;
        }

        $source = self::read($site, ['composeSource', 'compose_source']);
        $source = $source instanceof \BackedEnum ? $source->value : $source;

        if ($source !== null && $source !== 'inline') {
            return null;
        }

        $content = self::read($site, ['composeContent', 'compose_content']);

        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    public function publicServices(SiteData $site): array
    {
        $services = [];

        foreach ((array) (self::read($site, ['publicServices', 'public_services']) ?? []) as $entry) {
            $service = self::read($entry, ['service', 'name']);
            $port = self::read($entry, ['port', 'target']);

            if (is_string($service) && is_numeric($port)) {
                $domain = self::read($entry, ['domain']);
                $services[] = ['service' => $service, 'port' => (int) $port, 'domain' => is_string($domain) && $domain !== '' ? $domain : null];
            }
        }

        return $services;
    }

    /**
     * @param  list<string>  $keys
     */
    private static function read(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }

            if (is_object($source) && property_exists($source, $key)) {
                return $source->{$key};
            }
        }

        return null;
    }
}
