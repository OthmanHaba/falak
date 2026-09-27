<?php

namespace Kiln\Templates\Infrastructure;

use Kiln\Sites\Contracts\ComposeSites;
use Kiln\Sites\Contracts\ComposeSource;
use Kiln\Sites\Contracts\Data\PublicService;
use Kiln\Sites\Contracts\Data\SiteData;
use Kiln\Templates\Application\Compose\SiteCompose;

/**
 * {@see SiteCompose} over `SiteData::$compose` (source, public services) and the inline compose file from
 * `Sites\Contracts\ComposeSites` (docs/COMPOSE_TEMPLATES.md §5).
 */
final class SiteDataCompose implements SiteCompose
{
    public function __construct(private readonly ComposeSites $compose) {}

    public function content(SiteData $site): ?string
    {
        if ($site->compose?->source !== ComposeSource::Inline) {
            return null;
        }

        $content = $this->compose->content($site->id)?->content;

        return is_string($content) && trim($content) !== '' ? $content : null;
    }

    public function publicServices(SiteData $site): array
    {
        return array_map(
            fn (PublicService $public) => ['service' => $public->service, 'port' => $public->port, 'domain' => $public->domain],
            $site->compose->publicServices ?? [],
        );
    }
}
