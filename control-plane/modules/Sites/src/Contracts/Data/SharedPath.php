<?php

namespace Falak\Sites\Contracts\Data;

/**
 * A path linked from every release into shared/ (e.g. storage, .env, public/uploads).
 */
final readonly class SharedPath
{
    /**
     * @param  string  $path  relative to the release root, no leading slash
     * @param  'directory'|'file'  $type
     */
    public function __construct(
        public string $path,
        public string $type = 'directory',
    ) {}

    /**
     * @return array{path: string, type: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'type' => $this->type];
    }
}
