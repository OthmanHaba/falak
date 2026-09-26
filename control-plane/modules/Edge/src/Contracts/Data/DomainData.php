<?php

namespace Kiln\Edge\Contracts\Data;

use Kiln\Edge\Contracts\TlsMode;

final readonly class DomainData
{
    /**
     * @param  'none'|'to_www'|'to_apex'  $wwwRedirect
     */
    public function __construct(
        public string $id,
        public string $siteId,
        public string $name,
        public bool $primary,
        public string $wwwRedirect,
        public TlsMode $tls,
        public ?string $certificateId,
    ) {}

    public function isWildcard(): bool
    {
        return str_starts_with($this->name, '*.');
    }
}
