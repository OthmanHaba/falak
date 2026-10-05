<?php

namespace Falak\Sites\Contracts\Data;

final readonly class CreatedSite
{
    /**
     * @param  list<string>  $warnings  non-fatal source control problems (deploy key / webhook)
     */
    public function __construct(
        public SiteData $site,
        public array $warnings = [],
    ) {}
}
