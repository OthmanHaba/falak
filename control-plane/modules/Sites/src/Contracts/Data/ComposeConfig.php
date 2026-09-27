<?php

namespace Kiln\Sites\Contracts\Data;

use Kiln\Sites\Contracts\ComposeSource;

/**
 * Compose settings of a `compose` runtime site (SiteData::$compose).
 */
final readonly class ComposeConfig
{
    /**
     * @param  ?string  $file  repo source: compose file path (null = compose.yaml, then docker-compose.yml)
     * @param  list<PublicService>  $publicServices  first = primary (site domains + <slug> test domain)
     * @param  ?array{slug: string, version: string, source: string}  $template  template the site was created from
     * @param  ?int  $version  inline source: latest compose version
     */
    public function __construct(
        public ComposeSource $source,
        public ?string $file,
        public array $publicServices,
        public ?array $template = null,
        public ?int $version = null,
    ) {}

    public function primary(): ?PublicService
    {
        return $this->publicServices[0] ?? null;
    }

    public function publicService(string $service): ?PublicService
    {
        foreach ($this->publicServices as $public) {
            if ($public->service === $service) {
                return $public;
            }
        }

        return null;
    }
}
