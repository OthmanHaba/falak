<?php

namespace Kiln\Deployments\Contracts\Data;

/**
 * A function version as the agent's fn.release.apply needs it, with the function's current scaling and limits.
 */
final readonly class FunctionSource
{
    /**
     * @param  string  $hash  sha256 of the version's files and entrypoint (hex; recorded as the release commit)
     * @param  array<string, string>  $files  path => content
     * @param  array{min_instances: int, max_instances: int, concurrency: int, idle_timeout_s: int}  $scaling
     * @param  array{memory_bytes: int, cpus: float, pids: int, request_timeout_s: int, start_timeout_s: int}  $limits
     * @param  array{api_key_hashes?: list<string>, allow_cidrs?: list<string>}  $access  empty = public
     */
    public function __construct(
        public string $hash,
        public int $number,
        public ?string $message,
        public ?string $author,
        public string $runtime,
        public string $image,
        public string $entrypoint,
        public array $files,
        public array $scaling,
        public array $limits,
        public array $access = [],
    ) {}
}
