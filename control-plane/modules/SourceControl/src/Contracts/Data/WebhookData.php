<?php

namespace Kiln\SourceControl\Contracts\Data;

final readonly class WebhookData
{
    /**
     * @param  string  $url  public endpoint the provider posts push events to
     * @param  bool  $installed  true when registered through the provider API; false = configure manually with $url + secret
     */
    public function __construct(
        public string $id,
        public string $connectionId,
        public string $repository,
        public string $url,
        public bool $installed,
        public ?string $installError = null,
    ) {}
}
