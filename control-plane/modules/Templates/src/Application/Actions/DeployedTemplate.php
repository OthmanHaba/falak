<?php

namespace Kiln\Templates\Application\Actions;

use Kiln\Sites\Contracts\Data\SiteData;

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
