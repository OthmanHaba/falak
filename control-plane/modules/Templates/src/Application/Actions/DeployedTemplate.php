<?php

namespace Falak\Templates\Application\Actions;

use Falak\Sites\Contracts\Data\SiteData;

final readonly class DeployedTemplate
{
    /**
     * @param  list<string>  $warnings
     * @param  array<string, string>  $domains  public service => domain it is served on
     */
    public function __construct(
        public SiteData $site,
        public ?string $deploymentId,
        public array $warnings,
        public array $domains,
    ) {}
}
