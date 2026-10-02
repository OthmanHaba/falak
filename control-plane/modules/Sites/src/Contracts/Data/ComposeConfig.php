<?php

namespace Kiln\Sites\Contracts\Data;

use Kiln\Sites\Contracts\ComposeSource;

/**
 * Compose settings of a `compose` runtime site (SiteData::$compose).
 */
final readonly class ComposeConfig
{
    /** A service runs in the stack (default). */
    public const MODE_KEEP = 'keep';

    /** A service replaced by a Kiln-managed database. */
    public const MODE_DATABASE = 'database';

    /** A service taken out of the stack and run as its own Kiln site. */
    public const MODE_SITE = 'site';

    /**
     * @param  ?string  $file  repo source: first compose file (null = compose.yaml, then docker-compose.yml)
     * @param  list<PublicService>  $publicServices  first = primary (site domains + <slug> test domain)
     * @param  ?array{slug: string, version: string, source: string}  $template  template the site was created from
     * @param  ?int  $version  inline source: latest compose version
     * @param  list<string>  $files  repo source: compose files in `-f` order (empty = the default file)
     * @param  list<string>  $profiles  active compose profiles
     * @param  array<string, array{mode: string, database_id?: string, site_id?: string}>  $services  per-service decisions (absent = keep)
     * @param  array{keep_binds?: list<string>}  $adjustments  the user's choices about Kiln's adjustments ("service:./path" binds kept as folders)
     */
    public function __construct(
        public ComposeSource $source,
        public ?string $file,
        public array $publicServices,
        public ?array $template = null,
        public ?int $version = null,
        public array $files = [],
        public array $profiles = [],
        public array $services = [],
        public array $adjustments = [],
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

    /** keep | database | site */
    public function mode(string $service): string
    {
        return (string) ($this->services[$service]['mode'] ?? self::MODE_KEEP);
    }

    /**
     * Services that no longer run in the stack (replaced by a Kiln database or split into their own site).
     *
     * @return list<string>
     */
    public function extracted(): array
    {
        return array_values(array_map('strval', array_keys(array_filter($this->services, fn (array $d) => ($d['mode'] ?? self::MODE_KEEP) !== self::MODE_KEEP))));
    }
}
