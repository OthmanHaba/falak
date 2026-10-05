<?php

namespace Falak\Edge\Contracts\Data;

use Falak\Edge\Contracts\TlsMode;

final readonly class DomainData
{
    /**
     * @param  'none'|'to_www'|'to_apex'  $wwwRedirect
     * @param  ?string  $service  public service of a compose site the domain routes to (null: the site's own route)
     */
    public function __construct(
        public string $id,
        public string $siteId,
        public string $name,
        public bool $primary,
        public string $wwwRedirect,
        public TlsMode $tls,
        public ?string $certificateId,
        public ?string $service = null,
    ) {}

    public function isWildcard(): bool
    {
        return str_starts_with($this->name, '*.');
    }
}
