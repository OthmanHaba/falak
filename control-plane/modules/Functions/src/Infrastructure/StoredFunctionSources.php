<?php

namespace Kiln\Functions\Infrastructure;

use Kiln\Deployments\Contracts\Data\FunctionSource;
use Kiln\Deployments\Contracts\FunctionSources;
use Kiln\Functions\Application\FunctionStore;
use Kiln\Functions\Domain\Models\CloudFunction;
use Kiln\Functions\Domain\Models\FunctionVersion;

/**
 * Deployments' view of function code: a version plus the function's current runtime image, scaling and limits.
 */
final class StoredFunctionSources implements FunctionSources
{
    public function __construct(private readonly FunctionStore $functions) {}

    public function head(string $siteId): ?FunctionSource
    {
        $function = $this->functions->find($siteId);
        $version = $function?->head();

        return $function !== null && $version !== null ? $this->source($function, $version) : null;
    }

    public function find(string $siteId, string $hash): ?FunctionSource
    {
        $function = $this->functions->find($siteId);
        $version = $function !== null
            ? FunctionVersion::query()->where('function_id', $function->id)->where('hash', strtolower($hash))->orderByDesc('number')->first()
            : null;

        return $function !== null && $version !== null ? $this->source($function, $version) : null;
    }

    private function source(CloudFunction $function, FunctionVersion $version): FunctionSource
    {
        return new FunctionSource(
            hash: $version->hash,
            number: $version->number,
            message: $version->message,
            author: $version->author_name,
            runtime: $function->runtime,
            image: (string) config("functions.runtimes.{$function->runtime}.image"),
            entrypoint: $version->entrypoint,
            files: $version->files,
            scaling: $function->scaling(),
            limits: $function->limits(),
        );
    }
}
