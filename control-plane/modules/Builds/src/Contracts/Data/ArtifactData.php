<?php

namespace Kiln\Builds\Contracts\Data;

/**
 * A release tarball, shaped like deploy.fetch `artifact`. The URL is a short-lived bearer credential.
 */
final readonly class ArtifactData
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $url,
        public string $sha256,
        public int $sizeBytes,
        public string $format = 'tar.gz',
        public array $headers = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return array_filter([
            'url' => $this->url,
            'sha256' => $this->sha256,
            'size_bytes' => $this->sizeBytes,
            'format' => $this->format,
            'headers' => $this->headers === [] ? null : $this->headers,
        ], fn ($value) => $value !== null);
    }
}
